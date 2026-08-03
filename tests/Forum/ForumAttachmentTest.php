<?php

/**
 * Tests for AP_Forum_Attachment — uploads, quotas, post linking.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum;
use AP_Forum_Attachment;
use AP_Media;
use AP_Migrator;
use AP_Options;
use AP_Post;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Forum_Attachment::class)]
final class ForumAttachmentTest extends TestCase
{
    private string $root;

    private static string $uploadsTmp = '';

    private AP_DB $db;

    public static function setUpBeforeClass(): void
    {
        self::$uploadsTmp = sys_get_temp_dir() . '/ap-forum-attach-' . bin2hex(random_bytes(6));
        mkdir(self::$uploadsTmp, 0777, true);

        if (!defined('AP_UPLOADS_DIR')) {
            define('AP_UPLOADS_DIR', self::$uploadsTmp);
        }
        if (!defined('AP_UPLOADS_URL')) {
            define('AP_UPLOADS_URL', 'https://example.test/ap-content/uploads');
        }
        if (!defined('AP_UPLOADS_USE_YEARMONTH')) {
            define('AP_UPLOADS_USE_YEARMONTH', false);
        }
        if (!defined('AP_SITEURL')) {
            define('AP_SITEURL', 'https://example.test');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$uploadsTmp !== '' && is_dir(self::$uploadsTmp)) {
            self::removeTreeStatic(self::$uploadsTmp);
        }
    }

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-media.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/class-ap-forum-attachment.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Media::setBasedirOverride(self::$uploadsTmp);
        AP_Media::setBaseurlOverride('https://example.test/ap-content/uploads');
        $this->emptyDir(self::$uploadsTmp);

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        // Seed forum attachment defaults (installer would set these).
        AP_Options::update(AP_Forum_Attachment::OPTION_ENABLED, '1', $this->db);
        AP_Options::update(AP_Forum_Attachment::OPTION_MAX_SIZE, (string) AP_Forum_Attachment::DEFAULT_MAX_SIZE, $this->db);
        AP_Options::update(
            AP_Forum_Attachment::OPTION_ALLOWED_TYPES,
            AP_Forum_Attachment::DEFAULT_ALLOWED_TYPES,
            $this->db
        );
        AP_Options::update(
            AP_Forum_Attachment::OPTION_MAX_PER_POST,
            (string) AP_Forum_Attachment::DEFAULT_MAX_PER_POST,
            $this->db
        );
        AP_Options::update(
            AP_Forum_Attachment::OPTION_USER_QUOTA,
            (string) AP_Forum_Attachment::DEFAULT_USER_QUOTA,
            $this->db
        );
    }

    protected function tearDown(): void
    {
        AP_Media::setBasedirOverride(null);
        AP_Media::setBaseurlOverride(null);
        $this->emptyDir(self::$uploadsTmp);
    }

    public function testMigrationCreatesForumAttachmentsTable(): void
    {
        $this->assertGreaterThanOrEqual(6, (int) AP_DB_VERSION);
        $name = $this->db->getVar(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['ap_forum_attachments']
        );
        $this->assertSame('ap_forum_attachments', $name);
        $this->assertSame('ap_forum_attachments', $this->db->forum_attachments);
        $this->assertContains('forum_attachments', AP_Forum::baseTables());
    }

    public function testSettingsDefaultsAndDisabled(): void
    {
        $this->assertTrue(AP_Forum_Attachment::isEnabled($this->db));
        $this->assertSame(AP_Forum_Attachment::DEFAULT_MAX_SIZE, AP_Forum_Attachment::maxSizeBytes($this->db));
        $this->assertContains('pdf', AP_Forum_Attachment::allowedExtensions($this->db));
        $this->assertSame(5, AP_Forum_Attachment::maxPerPost($this->db));
        $this->assertSame(AP_Forum_Attachment::DEFAULT_USER_QUOTA, AP_Forum_Attachment::userQuotaBytes($this->db));

        AP_Options::update(AP_Forum_Attachment::OPTION_ENABLED, '0', $this->db);
        $this->assertFalse(AP_Forum_Attachment::isEnabled($this->db));
        $check = AP_Forum_Attachment::canUpload(1, 100, null, $this->db);
        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('disabled', strtolower($check['error']));
    }

    public function testUploadAttachesToPostAndEnforcesType(): void
    {
        [$forumId, $postId] = $this->seedForumPost();

        $result = AP_Forum_Attachment::handleUpload(
            $this->makeUploadFile('notes.txt', "hello attachment\n"),
            [
                'user_id' => 42,
                'post_id' => $postId,
                'test_mode' => true,
            ],
            $this->db
        );

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertGreaterThan(0, $result['id']);
        $this->assertGreaterThan(0, $result['media_id']);
        $this->assertNotSame('', $result['url']);

        $row = AP_Forum_Attachment::get($result['id'], $this->db);
        $this->assertNotNull($row);
        $this->assertSame($postId, (int) $row->post_id);
        $this->assertSame(42, (int) $row->user_id);
        $this->assertSame(0, (int) $row->is_orphan);
        $this->assertSame('notes.txt', $row->filename);
        $this->assertGreaterThan(0, (int) $row->filesize);

        $list = AP_Forum_Attachment::getForPost($postId, $this->db);
        $this->assertCount(1, $list);

        $display = AP_Forum_Attachment::getDisplayForPost($postId, $this->db);
        $this->assertCount(1, $display);
        $this->assertSame('notes.txt', $display[0]['filename']);
        $this->assertNotSame('', $display[0]['url']);

        // Disallowed extension for forums (even if media library allows it).
        // .md is not in default forum allow-list.
        $bad = AP_Forum_Attachment::handleUpload(
            $this->makeUploadFile('readme.md', "# title\n"),
            ['user_id' => 42, 'post_id' => $postId, 'test_mode' => true],
            $this->db
        );
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('not allowed', strtolower($bad['error']));
    }

    public function testOrphanAssignAndPostDisplayIncludesAttachments(): void
    {
        [$forumId, $postId] = $this->seedForumPost();

        $up = AP_Forum_Attachment::handleUpload(
            $this->makeUploadFile('orphan.pdf', "%PDF-1.4 fake content\n"),
            ['user_id' => 7, 'test_mode' => true],
            $this->db
        );
        $this->assertTrue($up['ok'], $up['error']);
        $orphan = AP_Forum_Attachment::get($up['id'], $this->db);
        $this->assertNotNull($orphan);
        $this->assertSame(1, (int) $orphan->is_orphan);
        $this->assertSame(0, (int) $orphan->post_id);

        $n = AP_Forum_Attachment::assignToPost([(int) $up['id']], $postId, $this->db);
        $this->assertSame(1, $n);
        $linked = AP_Forum_Attachment::get($up['id'], $this->db);
        $this->assertSame(0, (int) $linked?->is_orphan);
        $this->assertSame($postId, (int) $linked?->post_id);
        $this->assertSame($forumId, (int) $linked?->forum_id);

        $displayPosts = AP_Forum::getPostsDisplayData(
            (int) AP_Forum::getPost($postId, $this->db)->topic_id,
            [],
            $this->db
        );
        $this->assertNotEmpty($displayPosts);
        $this->assertArrayHasKey('attachments', $displayPosts[0]);
        $this->assertCount(1, $displayPosts[0]['attachments']);
        $this->assertSame('orphan.pdf', $displayPosts[0]['attachments'][0]['filename']);
    }

    public function testPerPostLimitAndUserQuota(): void
    {
        [, $postId] = $this->seedForumPost();

        AP_Options::update(AP_Forum_Attachment::OPTION_MAX_PER_POST, '2', $this->db);
        AP_Options::update(AP_Forum_Attachment::OPTION_USER_QUOTA, '100', $this->db);
        AP_Options::update(AP_Forum_Attachment::OPTION_MAX_SIZE, '1000', $this->db);

        $a = AP_Forum_Attachment::handleUpload(
            $this->makeUploadFile('a.txt', str_repeat('a', 40)),
            ['user_id' => 9, 'post_id' => $postId, 'test_mode' => true],
            $this->db
        );
        $this->assertTrue($a['ok'], $a['error']);

        $b = AP_Forum_Attachment::handleUpload(
            $this->makeUploadFile('b.txt', str_repeat('b', 40)),
            ['user_id' => 9, 'post_id' => $postId, 'test_mode' => true],
            $this->db
        );
        $this->assertTrue($b['ok'], $b['error']);

        $c = AP_Forum_Attachment::handleUpload(
            $this->makeUploadFile('c.txt', str_repeat('c', 10)),
            ['user_id' => 9, 'post_id' => $postId, 'test_mode' => true],
            $this->db
        );
        $this->assertFalse($c['ok']);
        $this->assertStringContainsString('maximum', strtolower($c['error']));

        // New post: per-post free, but user quota still applies (80 used of 100).
        [, $post2] = $this->seedForumPost('Other');
        $overQuota = AP_Forum_Attachment::handleUpload(
            $this->makeUploadFile('big.txt', str_repeat('x', 50)),
            ['user_id' => 9, 'post_id' => $post2, 'test_mode' => true],
            $this->db
        );
        $this->assertFalse($overQuota['ok']);
        $this->assertStringContainsString('quota', strtolower($overQuota['error']));

        $usage = AP_Forum_Attachment::userUsageBytes(9, $this->db);
        $this->assertGreaterThanOrEqual(80, $usage);
        $this->assertLessThanOrEqual(100, $usage);
    }

    public function testMaxFileSizeEnforced(): void
    {
        [, $postId] = $this->seedForumPost();
        AP_Options::update(AP_Forum_Attachment::OPTION_MAX_SIZE, '20', $this->db);

        $result = AP_Forum_Attachment::handleUpload(
            $this->makeUploadFile('large.txt', str_repeat('Z', 50)),
            ['user_id' => 1, 'post_id' => $postId, 'test_mode' => true],
            $this->db
        );
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('maximum', strtolower($result['error']));
    }

    public function testDeleteAttachmentRemovesFileAndCascadeOnPostDelete(): void
    {
        [, $postId] = $this->seedForumPost();
        $up = AP_Forum_Attachment::handleUpload(
            $this->makeUploadFile('bye.txt', "delete me\n"),
            ['user_id' => 3, 'post_id' => $postId, 'test_mode' => true],
            $this->db
        );
        $this->assertTrue($up['ok'], $up['error']);
        $mediaId = (int) $up['media_id'];
        $abs = AP_Media::getAttachedFile($mediaId, $this->db);
        $this->assertFileExists($abs);

        $this->assertTrue(AP_Forum_Attachment::delete((int) $up['id'], true, $this->db));
        $this->assertNull(AP_Forum_Attachment::get((int) $up['id'], $this->db));
        $this->assertNull(AP_Post::get($mediaId, $this->db));
        $this->assertFileDoesNotExist($abs);

        // Cascade: attach then delete reply post.
        $topic = AP_Forum::getPost($postId, $this->db);
        $replyId = AP_Forum::createReply([
            'topic_id' => (int) $topic->topic_id,
            'content' => 'With file',
            'poster_id' => 3,
        ], $this->db);
        $up2 = AP_Forum_Attachment::handleUpload(
            $this->makeUploadFile('reply.txt', "reply file\n"),
            ['user_id' => 3, 'post_id' => $replyId, 'test_mode' => true],
            $this->db
        );
        $this->assertTrue($up2['ok'], $up2['error']);
        $this->assertTrue(AP_Forum::deletePost($replyId, false, $this->db));
        $this->assertNull(AP_Forum_Attachment::get((int) $up2['id'], $this->db));
    }

    public function testForceDeleteTopicRemovesAttachments(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Files'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Topic with file',
            'content' => 'Body',
            'poster_id' => 1,
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $firstPost = (int) $topic->first_post_id;

        $up = AP_Forum_Attachment::handleUpload(
            $this->makeUploadFile('topic.txt', "topic file\n"),
            ['user_id' => 1, 'post_id' => $firstPost, 'test_mode' => true],
            $this->db
        );
        $this->assertTrue($up['ok'], $up['error']);

        $this->assertTrue(AP_Forum::deleteTopic($topicId, true, $this->db));
        $this->assertNull(AP_Forum_Attachment::get((int) $up['id'], $this->db));
    }

    public function testDownloadCounterAndProceduralHelpers(): void
    {
        [, $postId] = $this->seedForumPost();
        $up = AP_Forum_Attachment::handleUpload(
            $this->makeUploadFile('dl.txt', "data\n"),
            ['user_id' => 2, 'post_id' => $postId, 'test_mode' => true],
            $this->db
        );
        $this->assertTrue($up['ok'], $up['error']);
        $this->assertTrue(AP_Forum_Attachment::incrementDownload((int) $up['id'], $this->db));
        $row = AP_Forum_Attachment::get((int) $up['id'], $this->db);
        $this->assertSame(1, (int) $row?->download_count);

        $this->assertTrue(function_exists('ap_handle_forum_attachment_upload'));
        $this->assertTrue(function_exists('ap_get_forum_attachments'));
        $this->assertTrue(function_exists('ap_delete_forum_attachment'));
        $this->assertTrue(function_exists('ap_forum_attachments_enabled'));
        $this->assertTrue(ap_forum_attachments_enabled($this->db));
        $this->assertCount(1, ap_get_forum_attachments($postId, $this->db));
    }

    public function testAttachMediaHelper(): void
    {
        [, $postId] = $this->seedForumPost();
        $tmp = self::$uploadsTmp . '/direct.txt';
        file_put_contents($tmp, "via media\n");
        $mediaId = AP_Media::insertAttachment([
            'file' => 'direct.txt',
            'type' => 'text/plain',
            'post_title' => 'Direct',
            'post_author' => 5,
        ], $this->db);
        $this->assertGreaterThan(0, $mediaId);

        $attachId = AP_Forum_Attachment::attachMedia($mediaId, $postId, [
            'user_id' => 5,
            'filename' => 'direct.txt',
        ], $this->db);
        $this->assertGreaterThan(0, $attachId);
        $row = AP_Forum_Attachment::get($attachId, $this->db);
        $this->assertSame($mediaId, (int) $row?->media_id);
        $this->assertSame($postId, (int) $row?->post_id);
    }

    /**
     * @return array{0: int, 1: int} forum_id, first post_id
     */
    private function seedForumPost(string $name = 'General'): array
    {
        $forumId = AP_Forum::insertForum([
            'forum_name' => $name . ' ' . bin2hex(random_bytes(2)),
            'forum_type' => 'forum',
        ], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Topic ' . $name,
            'content' => 'First post body for attachments.',
            'poster_id' => 1,
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);

        return [$forumId, (int) $topic->first_post_id];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeUploadFile(string $name, string $contents): array
    {
        $tmp = self::$uploadsTmp . '/src-' . bin2hex(random_bytes(4)) . '-' . $name;
        file_put_contents($tmp, $contents);

        return [
            'name' => $name,
            'type' => 'application/octet-stream',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($contents),
        ];
    }

    private function emptyDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::removeTreeStatic($path);
            } else {
                @unlink($path);
            }
        }
    }

    private static function removeTreeStatic(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::removeTreeStatic($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
