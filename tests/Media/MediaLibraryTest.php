<?php

/**
 * Tests for AP_Media — uploads, attachments, query, delete.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Media;

use AP_Admin;
use AP_Admin_Media;
use AP_DB;
use AP_Media;
use AP_Media_List_Table;
use AP_Migrator;
use AP_Nonce;
use AP_Options;
use AP_Post;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Media::class)]
#[CoversClass(AP_Media_List_Table::class)]
#[CoversClass(AP_Admin_Media::class)]
final class MediaLibraryTest extends TestCase
{
    private string $root;

    private static string $uploadsTmp = '';

    private AP_DB $db;

    /** Privileged actor with upload_files (administrator). */
    private int $actorId = 0;

    public static function setUpBeforeClass(): void
    {
        self::$uploadsTmp = sys_get_temp_dir() . '/ap-media-test-' . bin2hex(random_bytes(6));
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
        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('n', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('s', 32));
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-' . str_repeat('a', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-' . str_repeat('b', 32));
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
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        require_once $this->root . '/ap-admin/includes/class-ap-media-list-table.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-media.php';

        // Prefer runtime override so suite order cannot pin AP_UPLOADS_DIR elsewhere.
        AP_Media::setBasedirOverride(self::$uploadsTmp);
        AP_Media::setBaseurlOverride('https://example.test/ap-content/uploads');

        // Clear leftover files between tests (shared uploads dir).
        $this->emptyDir(self::$uploadsTmp);

        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Post::resetRegistry();
        AP_Admin::clearNotices();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Roles::ensureDefaults($this->db);
        AP_Post::ensureBuiltins();

        $admin = AP_User::create([
            'user_login' => 'mediaadmin',
            'user_email' => 'mediaadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $this->actorId = (int) $admin['id'];
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Post::resetRegistry();
        AP_Admin::clearNotices();
        $this->emptyDir(self::$uploadsTmp);
        AP_Media::setBasedirOverride(null);
        AP_Media::setBaseurlOverride(null);
    }

    public function testSanitizeFilenameAndCheckTypes(): void
    {
        $this->assertSame('my-photo.jpg', AP_Media::sanitizeFilename('My Photo!!.JPG', 'jpg'));
        // basename() strips path segments; remaining name is sanitized.
        $this->assertSame('passwd.png', AP_Media::sanitizeFilename('../../../etc/passwd.png', 'png'));
        $this->assertSame('file.png', AP_Media::sanitizeFilename('....png', 'png'));

        $ok = AP_Media::checkFileType('report.pdf');
        $this->assertTrue($ok['ok']);
        $this->assertSame('pdf', $ok['ext']);
        $this->assertSame('application/pdf', $ok['type']);

        $bad = AP_Media::checkFileType('shell.php');
        $this->assertFalse($bad['ok']);

        $exe = AP_Media::checkFileType('image.php.jpg');
        $this->assertFalse($exe['ok']);

        $unknown = AP_Media::checkFileType('payload.exe');
        $this->assertFalse($unknown['ok']);

        $bat = AP_Media::checkFileType('run.cmd');
        $this->assertFalse($bat['ok']);
    }

    public function testRejectsCorruptRasterImage(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'apf');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'definitely-not-png-bytes');

        $check = AP_Media::checkFileType('photo.png', $tmp);
        $this->assertFalse($check['ok']);
        @unlink($tmp);
    }

    public function testRejectsSvgWithScript(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'aps');
        $this->assertNotFalse($tmp);
        file_put_contents(
            $tmp,
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );
        $check = AP_Media::checkFileType('icon.svg', $tmp);
        $this->assertFalse($check['ok']);
        @unlink($tmp);
    }

    public function testUploadDirUsesConfiguredBasedir(): void
    {
        $dir = AP_Media::uploadDir();
        $this->assertFalse($dir['error']);
        $this->assertSame(self::$uploadsTmp, $dir['basedir']);
        $this->assertStringStartsWith('https://example.test/ap-content/uploads', $dir['baseurl']);
        $this->assertDirectoryExists($dir['path']);
    }

    public function testHandleUploadCreatesAttachmentAndFile(): void
    {
        $tmp = $this->writeTempPng('hello.png');
        $result = AP_Media::handleUpload([
            'name' => 'Hello World.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($tmp),
        ], [
            'test_mode' => true,
            'post_author' => 1,
            'alt_text' => 'A test image',
        ], $this->db);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertGreaterThan(0, $result['id']);
        $this->assertSame('image/png', $result['type']);
        $this->assertNotSame('', $result['file']);
        $this->assertFileExists(AP_Media::basedir() . '/' . $result['file']);

        $post = AP_Post::get($result['id'], $this->db);
        $this->assertNotNull($post);
        $this->assertSame('attachment', $post->post_type);
        $this->assertSame('inherit', $post->post_status);
        $this->assertSame('image/png', $post->post_mime_type);
        $this->assertSame('hello world', strtolower($post->post_title));

        $this->assertSame('A test image', AP_Media::getAltText($result['id'], $this->db));
        $url = AP_Media::getAttachmentUrl($result['id'], $this->db);
        $this->assertStringContainsString('hello-world', $url);
        $this->assertStringStartsWith('https://example.test/ap-content/uploads/', $url);

        $meta = AP_Media::getMetadata($result['id'], $this->db);
        $this->assertArrayHasKey('filesize', $meta);
        $this->assertGreaterThan(0, (int) $meta['filesize']);
        $this->assertArrayHasKey('width', $meta);
        $this->assertArrayHasKey('height', $meta);
    }

    public function testRejectsMismatchedContent(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'apf');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, '<?php echo "nope";');

        $result = AP_Media::handleUpload([
            'name' => 'innocent.txt',
            'type' => 'text/plain',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($tmp),
        ], ['test_mode' => true], $this->db);

        $this->assertFalse($result['ok']);
        $this->assertNotSame('', $result['error']);
        @unlink($tmp);
    }

    public function testUpdateAndQueryAndDelete(): void
    {
        $id1 = $this->seedAttachment('alpha.txt', 'Alpha notes', 'text/plain', "plain text one\n");
        $id2 = $this->seedAttachment('beta.pdf', 'Beta report', 'application/pdf', "%PDF-1.4 fake\n");

        $this->assertGreaterThan(0, $id1);
        $this->assertGreaterThan(0, $id2);

        $ok = AP_Media::updateAttachment($id1, [
            'post_title' => 'Alpha Updated',
            'post_excerpt' => 'Caption here',
            'post_content' => 'Longer description',
            'alt_text' => 'not an image but ok',
        ], $this->db);
        $this->assertTrue($ok);

        $post = AP_Post::get($id1, $this->db);
        $this->assertNotNull($post);
        $this->assertSame('Alpha Updated', $post->post_title);
        $this->assertSame('Caption here', $post->post_excerpt);
        $this->assertSame('Longer description', $post->post_content);

        $found = AP_Media::query(['s' => 'Alpha', 'limit' => 10], $this->db);
        $this->assertSame(1, $found['total']);
        $this->assertCount(1, $found['items']);
        $this->assertSame($id1, $found['items'][0]->ID);

        $docs = AP_Media::query(['mime_type' => 'application/*'], $this->db);
        $this->assertGreaterThanOrEqual(1, $docs['total']);

        $counts = AP_Media::mimeTypeCounts($this->db);
        $this->assertGreaterThanOrEqual(2, $counts['all']);

        $filePath = AP_Media::getAttachedFile($id1, $this->db);
        $this->assertFileExists($filePath);
        $this->assertTrue(AP_Media::deleteAttachment($id1, $this->db));
        $this->assertFileDoesNotExist($filePath);
        $this->assertNull(AP_Post::get($id1, $this->db));
        $this->assertNotNull(AP_Post::get($id2, $this->db));
    }

    public function testListTableBulkDeleteAndRender(): void
    {
        $id1 = $this->seedAttachment('one.txt', 'One', 'text/plain', "one\n");
        $id2 = $this->seedAttachment('two.txt', 'Two', 'text/plain', "two\n");

        $table = new AP_Media_List_Table($this->db);
        $table->prepareItems(['mode' => 'list']);
        $this->assertGreaterThanOrEqual(2, $table->totalItems);

        $html = $table->render();
        $this->assertStringContainsString('One', $html);
        $this->assertStringContainsString('name="media[]"', $html);

        $grid = new AP_Media_List_Table($this->db);
        $grid->prepareItems(['mode' => 'grid']);
        $gridHtml = $grid->render();
        $this->assertStringContainsString('ap-media-grid', $gridHtml);

        $nonce = ap_create_nonce('bulk-media', $this->actorId);
        $result = $table->processBulkAction([
            'action' => 'delete',
            '_ap_nonce' => $nonce,
            'media' => [$id1, $id2],
        ], $this->actorId);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame(2, $result['count']);
        $this->assertNull(AP_Post::get($id1, $this->db));
    }

    public function testAdminMediaSaveWithNonce(): void
    {
        $id = $this->seedAttachment('edit-me.txt', 'Edit Me', 'text/plain', "body\n");
        // Attachments with author 0 still editable by administrators via edit_others_posts.
        $nonce = ap_create_nonce('update-media-' . $id, $this->actorId);

        $result = AP_Admin_Media::save([
            'attachment_id' => $id,
            '_ap_nonce' => $nonce,
            'post_title' => 'Edited Title',
            'post_excerpt' => 'Cap',
            'post_content' => 'Desc',
            'alt_text' => '',
            'post_parent' => 0,
        ], $this->actorId, $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $post = AP_Post::get($id, $this->db);
        $this->assertNotNull($post);
        $this->assertSame('Edited Title', $post->post_title);
        $this->assertSame('Cap', $post->post_excerpt);
    }

    public function testAdminMenuIncludesMedia(): void
    {
        $items = AP_Admin::menuItems('media');
        $ids = array_column($items, 'id');
        $this->assertContains('media', $ids);
        $media = null;
        foreach ($items as $item) {
            if ($item['id'] === 'media') {
                $media = $item;
                break;
            }
        }
        $this->assertNotNull($media);
        $this->assertTrue($media['active']);
        $this->assertStringContainsString('upload.php', $media['url']);
    }

    public function testProceduralHelpers(): void
    {
        $this->assertTrue(function_exists('ap_handle_upload'));
        $this->assertTrue(function_exists('ap_insert_attachment'));
        $this->assertTrue(function_exists('ap_delete_attachment'));
        $this->assertTrue(function_exists('ap_get_attachment_url'));
        $this->assertTrue(function_exists('ap_upload_dir'));

        $dir = ap_upload_dir();
        $this->assertArrayHasKey('basedir', $dir);
        $this->assertSame(self::$uploadsTmp, $dir['basedir']);

        $check = ap_check_filetype('photo.webp');
        $this->assertTrue($check['ok']);
    }

    public function testUniqueFilename(): void
    {
        $dir = self::$uploadsTmp;
        file_put_contents($dir . '/taken.txt', 'x');
        $name = AP_Media::uniqueFilename('taken.txt', $dir);
        $this->assertSame('taken-1.txt', $name);
    }

    public function testFormatBytes(): void
    {
        $this->assertSame('512 B', AP_Media::formatBytes(512));
        $this->assertSame('1 KB', AP_Media::formatBytes(1024));
        $this->assertStringContainsString('MB', AP_Media::formatBytes(2 * 1024 * 1024));
    }

    public function testGdScaleAndCropAndMaxDisplayWidth(): void
    {
        if (!AP_Media::gdAvailable()) {
            $this->markTestSkipped('GD extension required for image scale/crop tests.');
        }

        // Build a 40×20 solid PNG so scale/crop is observable.
        $src = imagecreatetruecolor(40, 20);
        $this->assertNotFalse($src);
        $blue = imagecolorallocate($src, 0, 80, 200);
        imagefilledrectangle($src, 0, 0, 39, 19, $blue);
        $tmp = sys_get_temp_dir() . '/ap-scale-' . bin2hex(random_bytes(4)) . '.png';
        imagepng($src, $tmp);
        imagedestroy($src);

        $result = AP_Media::handleUpload([
            'name' => 'scale-me.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($tmp),
        ], [
            'test_mode' => true,
            'post_author' => 1,
            'skip_rate_limit' => true,
        ], $this->db);
        @unlink($tmp);

        $this->assertTrue($result['ok'], $result['error']);
        $id = $result['id'];
        $meta = AP_Media::getMetadata($id, $this->db);
        $this->assertSame(40, (int) ($meta['width'] ?? 0));
        $this->assertSame(20, (int) ($meta['height'] ?? 0));

        // Scale down (fit): max width 20 → 20×10.
        $edit = AP_Media::editImage($id, 20, 0, false, $this->db);
        $this->assertTrue($edit['ok'], $edit['error']);
        $this->assertSame(20, $edit['width']);
        $this->assertSame(10, $edit['height']);
        $meta2 = AP_Media::getMetadata($id, $this->db);
        $this->assertSame(20, (int) ($meta2['width'] ?? 0));
        $this->assertSame(10, (int) ($meta2['height'] ?? 0));

        // Center crop to exact 8×8.
        $edit2 = AP_Media::editImage($id, 8, 8, true, $this->db);
        $this->assertTrue($edit2['ok'], $edit2['error']);
        $this->assertSame(8, $edit2['width']);
        $this->assertSame(8, $edit2['height']);

        // Max display width option + CSS printer.
        AP_Options::update(AP_Media::OPTION_MAX_DISPLAY_WIDTH, '900', $this->db);
        $this->assertSame(900, AP_Media::maxDisplayWidth($this->db));
        ob_start();
        AP_Media::printContentImageCss($this->db);
        $css = (string) ob_get_clean();
        $this->assertStringContainsString('ap-content-image-max', $css);
        $this->assertStringContainsString('900px', $css);
        $this->assertStringContainsString('max-width:min(100%', $css);

        // Admin form exposes scale/crop controls.
        $post = AP_Post::get($id, $this->db);
        $this->assertNotNull($post);
        $form = AP_Admin_Media::renderEditForm($post, $this->actorId, $this->db);
        $this->assertStringContainsString('image_scale_w', $form);
        $this->assertStringContainsString('image_crop', $form);
        $this->assertStringContainsString('edit_image', $form);
        $this->assertStringContainsString('Scale / crop', $form);
    }

    public function testResampleFileFitOnlyScalesDown(): void
    {
        if (!AP_Media::gdAvailable()) {
            $this->markTestSkipped('GD extension required.');
        }
        $src = imagecreatetruecolor(100, 50);
        $this->assertNotFalse($src);
        imagefilledrectangle($src, 0, 0, 99, 49, imagecolorallocate($src, 10, 20, 30));
        $in = sys_get_temp_dir() . '/ap-in-' . bin2hex(random_bytes(3)) . '.png';
        $out = sys_get_temp_dir() . '/ap-out-' . bin2hex(random_bytes(3)) . '.png';
        imagepng($src, $in);
        imagedestroy($src);

        $r = AP_Media::resampleFile($in, $out, 'image/png', 50, 0, false);
        $this->assertTrue($r['ok'], $r['error']);
        $this->assertSame(50, $r['width']);
        $this->assertSame(25, $r['height']);
        $this->assertFileExists($out);
        $info = getimagesize($out);
        $this->assertIsArray($info);
        $this->assertSame(50, $info[0]);
        $this->assertSame(25, $info[1]);

        @unlink($in);
        @unlink($out);
    }

    public function testUploadProtectionFiles(): void
    {
        $dir = self::$uploadsTmp;
        // Remove any leftover protection files from a previous test run.
        @unlink($dir . '/.htaccess');
        @unlink($dir . '/index.php');

        AP_Media::ensureProtectionFiles($dir);

        $this->assertFileExists($dir . '/.htaccess');
        $this->assertFileExists($dir . '/index.php');
        $ht = (string) file_get_contents($dir . '/.htaccess');
        $this->assertStringContainsString('FilesMatch', $ht);
        $this->assertStringContainsString('php', $ht);
        // Static media must remain allowed (do not blanket-deny all requests).
        $outsideFilesMatch = (string) (preg_replace(
            '/<FilesMatch[\s\S]*?<\/FilesMatch>/i',
            '',
            $ht
        ) ?? $ht);
        $this->assertStringNotContainsString('Require all denied', $outsideFilesMatch);
        $this->assertStringContainsString('Require all granted', $outsideFilesMatch);

        $template = AP_Media::uploadsHtaccessContents();
        $this->assertStringContainsString('block script execution', $template);
        $this->assertSame($template, $ht);

        // uploadDir() also ensures protection files exist.
        @unlink($dir . '/.htaccess');
        $upload = AP_Media::uploadDir();
        $this->assertFalse($upload['error']);
        $this->assertFileExists($dir . '/.htaccess');
    }

    /**
     * Minimal valid 1×1 PNG.
     */
    private function writeTempPng(string $name): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );
        $this->assertNotFalse($png);
        $path = sys_get_temp_dir() . '/ap-png-' . bin2hex(random_bytes(4)) . '-' . $name;
        file_put_contents($path, $png);

        return $path;
    }

    private function seedAttachment(string $filename, string $title, string $mime, string $contents): int
    {
        $safe = AP_Media::sanitizeFilename($filename);
        $dest = self::$uploadsTmp . '/' . $safe;
        file_put_contents($dest, $contents);

        // For text/plain allow content; for pdf skip strict finfo by using insertAttachment
        // after placing file (insert uses checkFileType which may fail on fake pdf).
        // Use AP_Post + meta directly when MIME detection would reject synthetic bytes.
        if ($mime === 'text/plain') {
            return AP_Media::insertAttachment([
                'file' => $safe,
                'type' => $mime,
                'post_title' => $title,
                'post_author' => 1,
            ], $this->db);
        }

        // Bypass content sniff for synthetic non-image files used in tests.
        $id = AP_Post::insert([
            'post_title' => $title,
            'post_status' => 'inherit',
            'post_type' => 'attachment',
            'post_mime_type' => $mime,
            'post_author' => 1,
            'guid' => AP_Media::baseurl() . '/' . $safe,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'meta' => [
                AP_Media::ATTACHED_FILE_META => $safe,
            ],
        ], $this->db);
        if ($id > 0) {
            AP_Post::updateMeta(
                $id,
                AP_Media::ATTACHMENT_META,
                (string) json_encode(['filesize' => strlen($contents), 'file' => $safe]),
                $this->db
            );
        }

        return $id;
    }

    private function emptyDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);

            return;
        }
        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
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
        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
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
