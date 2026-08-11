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

    public function testSiteIconPickerRenderAndResolve(): void
    {
        // Empty state: placeholder, upload, library, no remove checkbox body.
        $empty = AP_Admin_Media::renderSiteIconField(0, $this->actorId, $this->db);
        $this->assertStringContainsString('data-ap-site-icon-picker', $empty);
        $this->assertStringContainsString('name="site_icon"', $empty);
        $this->assertStringContainsString('type="hidden"', $empty);
        $this->assertStringContainsString('name="site_icon_upload"', $empty);
        $this->assertStringContainsString('site_icon_library', $empty);
        $this->assertStringContainsString('No site icon set', $empty);
        $this->assertStringContainsString('ap-site-icon-placeholder', $empty);
        // Remove control is present but hidden when none set.
        $this->assertStringContainsString('name="remove_site_icon"', $empty);
        $this->assertStringContainsString('data-ap-site-icon-remove-wrap', $empty);

        // Upload a PNG and select it.
        $tmp = $this->writeTempPng('icon.png');
        $up = AP_Media::handleUpload([
            'name' => 'site-icon.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($tmp),
        ], [
            'test_mode' => true,
            'post_author' => $this->actorId,
            'skip_rate_limit' => true,
        ], $this->db);
        $this->assertTrue($up['ok'], $up['error']);
        $id = $up['id'];

        $this->assertTrue(AP_Admin_Media::isUsableSiteIcon($id, $this->db));
        $this->assertFalse(AP_Admin_Media::isUsableSiteIcon(0, $this->db));
        $this->assertFalse(AP_Admin_Media::isUsableSiteIcon(999999, $this->db));

        $withIcon = AP_Admin_Media::renderSiteIconField($id, $this->actorId, $this->db);
        $this->assertStringContainsString('ap-site-icon-img', $withIcon);
        $this->assertStringContainsString('attachment #' . $id, $withIcon);
        $this->assertStringContainsString('value="' . $id . '"', $withIcon);
        $this->assertStringContainsString('Remove site icon', $withIcon);

        $candidates = AP_Admin_Media::listSiteIconCandidates(10, $this->db);
        $this->assertNotEmpty($candidates);
        $ids = array_column($candidates, 'id');
        $this->assertContains($id, $ids);

        // Resolve: pick from library.
        $pick = AP_Admin_Media::resolveSiteIconInput(
            ['site_icon' => (string) $id],
            [],
            $this->actorId,
            $this->db
        );
        $this->assertTrue($pick['ok']);
        $this->assertSame($id, $pick['site_icon']);
        $this->assertSame([], $pick['errors']);

        // Resolve: remove.
        $clear = AP_Admin_Media::resolveSiteIconInput(
            ['site_icon' => (string) $id, 'remove_site_icon' => '1'],
            [],
            $this->actorId,
            $this->db
        );
        $this->assertTrue($clear['ok']);
        $this->assertSame(0, $clear['site_icon']);

        // Resolve: invalid attachment.
        $bad = AP_Admin_Media::resolveSiteIconInput(
            ['site_icon' => '404404'],
            [],
            $this->actorId,
            $this->db
        );
        $this->assertFalse($bad['ok']);
        $this->assertSame(0, $bad['site_icon']);
        $this->assertNotEmpty($bad['errors']);

        // Resolve: omitted key → sentinel -1 (preserve option).
        $omit = AP_Admin_Media::resolveSiteIconInput([], [], $this->actorId, $this->db);
        $this->assertTrue($omit['ok']);
        $this->assertSame(-1, $omit['site_icon']);

        // Resolve: upload creates attachment and wins over remove.
        $tmp2 = $this->writeTempPng('icon2.png');
        $uploadResolve = AP_Admin_Media::resolveSiteIconInput(
            ['site_icon' => '0', 'remove_site_icon' => '1'],
            [
                'site_icon_upload' => [
                    'name' => 'fresh-icon.png',
                    'type' => 'image/png',
                    'tmp_name' => $tmp2,
                    'error' => UPLOAD_ERR_OK,
                    'size' => (int) filesize($tmp2),
                ],
            ],
            $this->actorId,
            $this->db,
            ['test_mode' => true, 'skip_rate_limit' => true]
        );
        $this->assertTrue($uploadResolve['ok'], implode('; ', $uploadResolve['errors']));
        $this->assertGreaterThan(0, $uploadResolve['site_icon']);
        $this->assertNotSame($id, $uploadResolve['site_icon']);
        $this->assertTrue(AP_Admin_Media::isUsableSiteIcon($uploadResolve['site_icon'], $this->db));

        // Accept attribute is image-focused.
        $accept = AP_Admin_Media::siteIconAcceptAttribute();
        $this->assertStringContainsString('image/png', $accept);
        $this->assertStringContainsString('.ico', $accept);

        // Progressive JS helper is non-empty and references picker hooks.
        $js = AP_Admin_Media::siteIconPickerScript();
        $this->assertStringContainsString('data-ap-site-icon-picker', $js);
        $this->assertStringContainsString('createObjectURL', $js);
    }

    public function testSiteIconEndToEndViaGeneralSettings(): void
    {
        require_once $this->root . '/ap-includes/class-ap-settings.php';
        \AP_Settings::flush();
        \AP_Settings::registerCore();

        $tmp = $this->writeTempPng('favicon.png');
        $resolved = AP_Admin_Media::resolveSiteIconInput(
            [
                'blogname' => 'Icon Site',
                'admin_email' => 'admin@example.test',
                'site_icon' => '0',
            ],
            [
                'site_icon_upload' => [
                    'name' => 'favicon.png',
                    'type' => 'image/png',
                    'tmp_name' => $tmp,
                    'error' => UPLOAD_ERR_OK,
                    'size' => (int) filesize($tmp),
                ],
            ],
            $this->actorId,
            $this->db,
            ['test_mode' => true, 'skip_rate_limit' => true]
        );
        $this->assertTrue($resolved['ok'], implode('; ', $resolved['errors']));

        $ok = AP_Options::updateGeneralSettings([
            'blogname' => 'Icon Site',
            'admin_email' => 'admin@example.test',
            'site_icon' => $resolved['site_icon'],
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame($resolved['site_icon'], AP_Options::siteIcon($this->db));

        // Remove via resolve + settings save.
        $cleared = AP_Admin_Media::resolveSiteIconInput(
            ['site_icon' => (string) $resolved['site_icon'], 'remove_site_icon' => '1'],
            [],
            $this->actorId,
            $this->db
        );
        $this->assertTrue($cleared['ok']);
        $this->assertSame(0, $cleared['site_icon']);
        $ok2 = AP_Options::updateGeneralSettings([
            'blogname' => 'Icon Site',
            'admin_email' => 'admin@example.test',
            'site_icon' => $cleared['site_icon'],
        ], $this->db);
        $this->assertTrue($ok2);
        $this->assertSame(0, AP_Options::siteIcon($this->db));
    }

    public function testProcessSiteIconSaveRequiresNonceAndManageOptions(): void
    {
        $this->assertSame('manage_options', AP_Admin_Media::SITE_ICON_CAPABILITY);
        $this->assertSame('ap_settings_general', AP_Admin_Media::SITE_ICON_NONCE_ACTION);
        $this->assertTrue(AP_Admin_Media::userCanManageSiteIcon($this->actorId, $this->db));

        // Seed an existing icon so failed saves must not clobber it.
        AP_Options::update('site_icon', '77', $this->db);
        $this->assertSame(77, AP_Options::siteIcon($this->db));

        $tmp = $this->writeTempPng('secure-icon.png');
        $up = AP_Media::handleUpload([
            'name' => 'secure-icon.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($tmp),
        ], [
            'test_mode' => true,
            'post_author' => $this->actorId,
            'skip_rate_limit' => true,
        ], $this->db);
        $this->assertTrue($up['ok'], $up['error']);
        $newId = $up['id'];

        // Missing nonce → reject, option unchanged.
        $noNonce = AP_Admin_Media::processSiteIconSave(
            ['site_icon' => (string) $newId],
            [],
            $this->actorId,
            $this->db,
            ['persist' => true]
        );
        $this->assertFalse($noNonce['ok']);
        $this->assertSame('nonce', $noNonce['message_key']);
        $this->assertFalse($noNonce['saved']);
        $this->assertSame(77, AP_Options::siteIcon($this->db));

        // Wrong nonce action → reject.
        $badNonce = AP_Admin_Media::processSiteIconSave(
            [
                'site_icon' => (string) $newId,
                '_ap_nonce' => ap_create_nonce('wrong-action', $this->actorId),
            ],
            [],
            $this->actorId,
            $this->db,
            ['persist' => true]
        );
        $this->assertFalse($badNonce['ok']);
        $this->assertSame('nonce', $badNonce['message_key']);
        $this->assertSame(77, AP_Options::siteIcon($this->db));

        // Subscriber lacks manage_options → reject even with valid nonce.
        $sub = AP_User::create([
            'user_login' => 'iconsub',
            'user_email' => 'iconsub@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $subId = (int) $sub['id'];
        $this->assertFalse(AP_Admin_Media::userCanManageSiteIcon($subId, $this->db));
        $this->assertFalse(AP_Roles::userCan($subId, 'manage_options', null, $this->db));

        $noCap = AP_Admin_Media::processSiteIconSave(
            [
                'site_icon' => (string) $newId,
                '_ap_nonce' => ap_create_nonce(
                    AP_Admin_Media::SITE_ICON_NONCE_ACTION,
                    $subId
                ),
            ],
            [],
            $subId,
            $this->db,
            ['persist' => true]
        );
        $this->assertFalse($noCap['ok']);
        $this->assertSame('cap', $noCap['message_key']);
        $this->assertFalse($noCap['saved']);
        $this->assertSame(77, AP_Options::siteIcon($this->db));

        // Admin + valid general-settings nonce → persist new icon.
        $ok = AP_Admin_Media::processSiteIconSave(
            [
                'site_icon' => (string) $newId,
                '_ap_nonce' => ap_create_nonce(
                    AP_Admin_Media::SITE_ICON_NONCE_ACTION,
                    $this->actorId
                ),
            ],
            [],
            $this->actorId,
            $this->db,
            ['persist' => true]
        );
        $this->assertTrue($ok['ok'], implode('; ', $ok['errors']));
        $this->assertSame('ok', $ok['message_key']);
        $this->assertTrue($ok['saved']);
        $this->assertSame($newId, $ok['site_icon']);
        $this->assertSame($newId, AP_Options::siteIcon($this->db));

        // Admin + nonce + remove → clear to 0.
        $clear = AP_Admin_Media::processSiteIconSave(
            [
                'site_icon' => (string) $newId,
                'remove_site_icon' => '1',
                '_ap_nonce' => ap_create_nonce(
                    AP_Admin_Media::SITE_ICON_NONCE_ACTION,
                    $this->actorId
                ),
            ],
            [],
            $this->actorId,
            $this->db,
            ['persist' => true]
        );
        $this->assertTrue($clear['ok'], implode('; ', $clear['errors']));
        $this->assertTrue($clear['saved']);
        $this->assertSame(0, $clear['site_icon']);
        $this->assertSame(0, AP_Options::siteIcon($this->db));

        // Upload path also requires nonce + manage_options.
        $tmp2 = $this->writeTempPng('upload-secure.png');
        $upload = AP_Admin_Media::processSiteIconSave(
            [
                'site_icon' => '0',
                '_ap_nonce' => ap_create_nonce(
                    AP_Admin_Media::SITE_ICON_NONCE_ACTION,
                    $this->actorId
                ),
            ],
            [
                'site_icon_upload' => [
                    'name' => 'upload-secure.png',
                    'type' => 'image/png',
                    'tmp_name' => $tmp2,
                    'error' => UPLOAD_ERR_OK,
                    'size' => (int) filesize($tmp2),
                ],
            ],
            $this->actorId,
            $this->db,
            ['persist' => true, 'test_mode' => true, 'skip_rate_limit' => true]
        );
        $this->assertTrue($upload['ok'], implode('; ', $upload['errors']));
        $this->assertTrue($upload['saved']);
        $this->assertGreaterThan(0, $upload['site_icon']);
        $this->assertSame($upload['site_icon'], AP_Options::siteIcon($this->db));
    }

    public function testGeneralSettingsScreenSiteIconSaveGates(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/options-general.php');
        $this->assertStringContainsString("requireCapability('manage_options')", $src);
        $this->assertStringContainsString('processSiteIconSave', $src);
        $this->assertStringContainsString('AP_Settings::settingsFields(\'general\')', $src);
        // Nonce / cap failures must surface before updateGeneralSettings.
        $processPos = strpos($src, 'processSiteIconSave');
        $savePos = strpos($src, 'updateGeneralSettings');
        $this->assertNotFalse($processPos);
        $this->assertNotFalse($savePos);
        $this->assertLessThan($savePos, $processPos);
        $this->assertStringContainsString("message_key'] === 'nonce'", $src);
        $this->assertStringContainsString("message_key'] === 'cap'", $src);
    }

    public function testGenerateSiteIconSizesCreatesPngAndIco(): void
    {
        if (!AP_Media::imageEditorAvailable()) {
            $this->markTestSkipped('GD or Imagick required for site icon derivatives.');
        }

        $this->assertSame([32, 180, 192, 512], AP_Media::SITE_ICON_SIZES);
        $this->assertSame('site_icon-32', AP_Media::siteIconSizeName(32));
        $this->assertTrue(AP_Media::imageEditorAvailable());

        $tmp = $this->writeTempPngSized('site-icon-src.png', 64, 48);
        $up = AP_Media::handleUpload([
            'name' => 'site-icon-src.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($tmp),
        ], [
            'test_mode' => true,
            'post_author' => $this->actorId,
            'skip_rate_limit' => true,
        ], $this->db);
        $this->assertTrue($up['ok'], $up['error']);
        $id = $up['id'];

        $gen = AP_Media::generateSiteIconSizes($id, $this->db);
        $this->assertTrue($gen['ok'], $gen['error']);
        $this->assertArrayHasKey('32', $gen['sizes']);
        $this->assertArrayHasKey('180', $gen['sizes']);
        $this->assertArrayHasKey('192', $gen['sizes']);
        $this->assertArrayHasKey('512', $gen['sizes']);
        // ICO should be present (PNG-in-ICO fallback when Imagick missing).
        $this->assertArrayHasKey('ico', $gen['sizes']);

        $stored = AP_Media::getSiteIconSizes($id, $this->db);
        $this->assertSame(array_keys($gen['sizes']), array_keys($stored));

        foreach ([32, 180, 192, 512] as $px) {
            $path = AP_Media::getSiteIconPath($id, $px, $this->db);
            $this->assertNotSame('', $path, "missing path for {$px}px");
            $this->assertFileExists($path);
            $info = getimagesize($path);
            $this->assertIsArray($info);
            $this->assertSame($px, $info[0], "width for {$px}");
            $this->assertSame($px, $info[1], "height for {$px}");
            $this->assertSame('image/png', $info['mime'] ?? '');

            $url = AP_Media::getSiteIconUrl($id, $px, $this->db);
            $this->assertStringContainsString('site_icon-' . $px, $url);
            $this->assertStringEndsWith('.png', $url);
        }

        $icoPath = AP_Media::getSiteIconPath($id, 'ico', $this->db);
        $this->assertNotSame('', $icoPath);
        $this->assertFileExists($icoPath);
        $this->assertStringEndsWith('.ico', $icoPath);
        $icoBytes = (string) file_get_contents($icoPath);
        // ICO magic: reserved=0, type=1 (icon).
        $this->assertGreaterThan(22, strlen($icoBytes));
        $this->assertSame(0, unpack('v', substr($icoBytes, 0, 2))[1]);
        $this->assertSame(1, unpack('v', substr($icoBytes, 2, 2))[1]);

        $icoUrl = AP_Media::getSiteIconUrl($id, 'ico', $this->db);
        $this->assertStringContainsString('site_icon.ico', $icoUrl);

        // Regenerate replaces previous derivatives (no duplicate junk).
        $before = $stored;
        $gen2 = AP_Media::generateSiteIconSizes($id, $this->db);
        $this->assertTrue($gen2['ok'], $gen2['error']);
        foreach ([32, 180, 192, 512] as $px) {
            $this->assertFileExists(AP_Media::getSiteIconPath($id, $px, $this->db));
        }
        // Same basenames as first run (stable naming).
        foreach (['32', '180', '192', '512', 'ico'] as $key) {
            $this->assertSame(
                $before[$key]['file'] ?? null,
                $gen2['sizes'][$key]['file'] ?? null,
                "stable name for {$key}"
            );
        }
    }

    public function testSiteIconDerivativesGeneratedOnSetViaGeneralSettings(): void
    {
        if (!AP_Media::imageEditorAvailable()) {
            $this->markTestSkipped('GD or Imagick required for site icon derivatives.');
        }

        require_once $this->root . '/ap-includes/class-ap-settings.php';
        \AP_Settings::flush();
        \AP_Settings::registerCore();

        $tmp = $this->writeTempPngSized('favicon-set.png', 80, 80);
        $up = AP_Media::handleUpload([
            'name' => 'favicon-set.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($tmp),
        ], [
            'test_mode' => true,
            'post_author' => $this->actorId,
            'skip_rate_limit' => true,
        ], $this->db);
        $this->assertTrue($up['ok'], $up['error']);
        $id = $up['id'];

        // Before set: no derivatives.
        $this->assertSame([], AP_Media::getSiteIconSizes($id, $this->db));

        $ok = AP_Options::updateGeneralSettings([
            'blogname' => 'Icon Site',
            'admin_email' => 'admin@example.test',
            'site_icon' => $id,
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame($id, AP_Options::siteIcon($this->db));

        $sizes = AP_Media::getSiteIconSizes($id, $this->db);
        foreach (['32', '180', '192', '512'] as $key) {
            $this->assertArrayHasKey($key, $sizes, "missing size {$key}");
            $this->assertFileExists(AP_Media::getSiteIconPath($id, (int) $key, $this->db));
        }
        // ICO or 32px fallback path must resolve.
        $this->assertNotSame('', AP_Media::getSiteIconPath($id, 'ico', $this->db));
        $this->assertArrayHasKey('ico', $sizes);
    }

    public function testSiteIconDerivativesGeneratedOnPersistSave(): void
    {
        if (!AP_Media::imageEditorAvailable()) {
            $this->markTestSkipped('GD or Imagick required for site icon derivatives.');
        }

        $tmp = $this->writeTempPngSized('persist-icon.png', 40, 40);
        $up = AP_Media::handleUpload([
            'name' => 'persist-icon.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($tmp),
        ], [
            'test_mode' => true,
            'post_author' => $this->actorId,
            'skip_rate_limit' => true,
        ], $this->db);
        $this->assertTrue($up['ok'], $up['error']);
        $id = $up['id'];

        $result = AP_Admin_Media::processSiteIconSave(
            [
                'site_icon' => (string) $id,
                '_ap_nonce' => ap_create_nonce(
                    AP_Admin_Media::SITE_ICON_NONCE_ACTION,
                    $this->actorId
                ),
            ],
            [],
            $this->actorId,
            $this->db,
            ['persist' => true]
        );
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertTrue($result['saved']);
        $this->assertSame($id, AP_Options::siteIcon($this->db));

        $sizes = AP_Media::getSiteIconSizes($id, $this->db);
        $this->assertArrayHasKey('32', $sizes);
        $this->assertArrayHasKey('512', $sizes);
        $this->assertFileExists(AP_Media::getSiteIconPath($id, 32, $this->db));
        $this->assertFileExists(AP_Media::getSiteIconPath($id, 512, $this->db));
    }

    public function testGenerateSiteIconSizesRejectsInvalidAttachment(): void
    {
        $bad = AP_Media::generateSiteIconSizes(0, $this->db);
        $this->assertFalse($bad['ok']);
        $this->assertSame([], $bad['sizes']);

        $missing = AP_Media::generateSiteIconSizes(999999, $this->db);
        $this->assertFalse($missing['ok']);
    }

    public function testCleanupSiteIconDerivativesRemovesFilesAndMeta(): void
    {
        if (!AP_Media::imageEditorAvailable()) {
            $this->markTestSkipped('GD or Imagick required for site icon derivatives.');
        }

        $tmp = $this->writeTempPngSized('cleanup-icon.png', 64, 64);
        $up = AP_Media::handleUpload([
            'name' => 'cleanup-icon.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($tmp),
        ], [
            'test_mode' => true,
            'post_author' => $this->actorId,
            'skip_rate_limit' => true,
        ], $this->db);
        $this->assertTrue($up['ok'], $up['error']);
        $id = $up['id'];

        $gen = AP_Media::generateSiteIconSizes($id, $this->db);
        $this->assertTrue($gen['ok'], $gen['error']);

        $paths = [];
        foreach ([32, 180, 192, 512, 'ico'] as $size) {
            $path = AP_Media::getSiteIconPath($id, $size, $this->db);
            $this->assertNotSame('', $path, "path for {$size}");
            $this->assertFileExists($path);
            $paths[(string) $size] = $path;
        }
        $original = AP_Media::getAttachedFile($id, $this->db);
        $this->assertFileExists($original);

        $this->assertTrue(AP_Media::cleanupSiteIconDerivatives($id, $this->db));
        $this->assertSame([], AP_Media::getSiteIconSizes($id, $this->db));
        foreach ($paths as $size => $path) {
            $this->assertFileDoesNotExist($path, "derivative {$size} should be deleted");
        }
        // Original media file is kept (remove clears the option pack, not the library item).
        $this->assertFileExists($original);
        $this->assertFalse(AP_Media::cleanupSiteIconDerivatives(0, $this->db));
    }

    public function testSiteIconCleanupOnRemoveViaGeneralSettings(): void
    {
        if (!AP_Media::imageEditorAvailable()) {
            $this->markTestSkipped('GD or Imagick required for site icon derivatives.');
        }

        require_once $this->root . '/ap-includes/class-ap-settings.php';
        \AP_Settings::flush();
        \AP_Settings::registerCore();

        $tmp = $this->writeTempPngSized('remove-icon.png', 48, 48);
        $up = AP_Media::handleUpload([
            'name' => 'remove-icon.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($tmp),
        ], [
            'test_mode' => true,
            'post_author' => $this->actorId,
            'skip_rate_limit' => true,
        ], $this->db);
        $this->assertTrue($up['ok'], $up['error']);
        $id = $up['id'];

        $ok = AP_Options::updateGeneralSettings([
            'blogname' => 'Cleanup Site',
            'admin_email' => 'admin@example.test',
            'site_icon' => $id,
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame($id, AP_Options::siteIcon($this->db));

        $path32 = AP_Media::getSiteIconPath($id, 32, $this->db);
        $pathIco = AP_Media::getSiteIconPath($id, 'ico', $this->db);
        $this->assertFileExists($path32);
        $this->assertFileExists($pathIco);
        $original = AP_Media::getAttachedFile($id, $this->db);

        // Remove site icon → derivatives gone, original retained, option 0.
        $ok2 = AP_Options::updateGeneralSettings([
            'blogname' => 'Cleanup Site',
            'admin_email' => 'admin@example.test',
            'site_icon' => 0,
        ], $this->db);
        $this->assertTrue($ok2);
        $this->assertSame(0, AP_Options::siteIcon($this->db));
        $this->assertSame([], AP_Media::getSiteIconSizes($id, $this->db));
        $this->assertFileDoesNotExist($path32);
        $this->assertFileDoesNotExist($pathIco);
        $this->assertFileExists($original);
    }

    public function testSiteIconCleanupOnReplaceViaGeneralSettings(): void
    {
        if (!AP_Media::imageEditorAvailable()) {
            $this->markTestSkipped('GD or Imagick required for site icon derivatives.');
        }

        require_once $this->root . '/ap-includes/class-ap-settings.php';
        \AP_Settings::flush();
        \AP_Settings::registerCore();

        $upload = function (string $name) {
            $tmp = $this->writeTempPngSized($name, 56, 56);
            $up = AP_Media::handleUpload([
                'name' => $name,
                'type' => 'image/png',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => (int) filesize($tmp),
            ], [
                'test_mode' => true,
                'post_author' => $this->actorId,
                'skip_rate_limit' => true,
            ], $this->db);
            $this->assertTrue($up['ok'], $up['error']);

            return $up['id'];
        };

        $oldId = $upload('old-site-icon.png');
        $newId = $upload('new-site-icon.png');

        $ok = AP_Options::updateGeneralSettings([
            'blogname' => 'Replace Site',
            'admin_email' => 'admin@example.test',
            'site_icon' => $oldId,
        ], $this->db);
        $this->assertTrue($ok);

        $oldPath32 = AP_Media::getSiteIconPath($oldId, 32, $this->db);
        $oldPathIco = AP_Media::getSiteIconPath($oldId, 'ico', $this->db);
        $this->assertFileExists($oldPath32);
        $this->assertFileExists($oldPathIco);
        $oldOriginal = AP_Media::getAttachedFile($oldId, $this->db);

        $ok2 = AP_Options::updateGeneralSettings([
            'blogname' => 'Replace Site',
            'admin_email' => 'admin@example.test',
            'site_icon' => $newId,
        ], $this->db);
        $this->assertTrue($ok2);
        $this->assertSame($newId, AP_Options::siteIcon($this->db));

        // Previous attachment pack removed.
        $this->assertSame([], AP_Media::getSiteIconSizes($oldId, $this->db));
        $this->assertFileDoesNotExist($oldPath32);
        $this->assertFileDoesNotExist($oldPathIco);
        $this->assertFileExists($oldOriginal);

        // New attachment has a fresh pack.
        $newSizes = AP_Media::getSiteIconSizes($newId, $this->db);
        $this->assertArrayHasKey('32', $newSizes);
        $this->assertArrayHasKey('512', $newSizes);
        $this->assertFileExists(AP_Media::getSiteIconPath($newId, 32, $this->db));
        $this->assertFileExists(AP_Media::getSiteIconPath($newId, 'ico', $this->db));
    }

    public function testSiteIconCleanupOnRemoveViaProcessSiteIconSavePersist(): void
    {
        if (!AP_Media::imageEditorAvailable()) {
            $this->markTestSkipped('GD or Imagick required for site icon derivatives.');
        }

        $tmp = $this->writeTempPngSized('persist-remove.png', 40, 40);
        $up = AP_Media::handleUpload([
            'name' => 'persist-remove.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($tmp),
        ], [
            'test_mode' => true,
            'post_author' => $this->actorId,
            'skip_rate_limit' => true,
        ], $this->db);
        $this->assertTrue($up['ok'], $up['error']);
        $id = $up['id'];

        $set = AP_Admin_Media::processSiteIconSave(
            [
                'site_icon' => (string) $id,
                '_ap_nonce' => ap_create_nonce(
                    AP_Admin_Media::SITE_ICON_NONCE_ACTION,
                    $this->actorId
                ),
            ],
            [],
            $this->actorId,
            $this->db,
            ['persist' => true]
        );
        $this->assertTrue($set['ok'], implode('; ', $set['errors']));
        $path32 = AP_Media::getSiteIconPath($id, 32, $this->db);
        $this->assertFileExists($path32);

        $clear = AP_Admin_Media::processSiteIconSave(
            [
                'site_icon' => (string) $id,
                'remove_site_icon' => '1',
                '_ap_nonce' => ap_create_nonce(
                    AP_Admin_Media::SITE_ICON_NONCE_ACTION,
                    $this->actorId
                ),
            ],
            [],
            $this->actorId,
            $this->db,
            ['persist' => true]
        );
        $this->assertTrue($clear['ok'], implode('; ', $clear['errors']));
        $this->assertTrue($clear['saved']);
        $this->assertSame(0, AP_Options::siteIcon($this->db));
        $this->assertSame([], AP_Media::getSiteIconSizes($id, $this->db));
        $this->assertFileDoesNotExist($path32);
        $this->assertFileExists(AP_Media::getAttachedFile($id, $this->db));
    }

    public function testDeleteAttachmentRemovesSiteIconDerivatives(): void
    {
        if (!AP_Media::imageEditorAvailable()) {
            $this->markTestSkipped('GD or Imagick required for site icon derivatives.');
        }

        $tmp = $this->writeTempPngSized('delete-with-icon.png', 36, 36);
        $up = AP_Media::handleUpload([
            'name' => 'delete-with-icon.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($tmp),
        ], [
            'test_mode' => true,
            'post_author' => $this->actorId,
            'skip_rate_limit' => true,
        ], $this->db);
        $this->assertTrue($up['ok'], $up['error']);
        $id = $up['id'];

        $gen = AP_Media::generateSiteIconSizes($id, $this->db);
        $this->assertTrue($gen['ok'], $gen['error']);
        $paths = [];
        foreach ([32, 180, 192, 512, 'ico'] as $size) {
            $path = AP_Media::getSiteIconPath($id, $size, $this->db);
            $this->assertFileExists($path);
            $paths[] = $path;
        }
        $original = AP_Media::getAttachedFile($id, $this->db);

        $this->assertTrue(AP_Media::deleteAttachment($id, $this->db));
        $this->assertFileDoesNotExist($original);
        foreach ($paths as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function testSiteIconMetaTagsEmptyWhenUnset(): void
    {
        AP_Options::update('site_icon', '0', $this->db);
        AP_Options::flushCache();

        $this->assertSame(0, AP_Options::siteIcon($this->db));
        $this->assertSame([], AP_Media::getSiteIconMetaTags($this->db));

        ob_start();
        AP_Media::printSiteIconTags($this->db);
        $html = (string) ob_get_clean();
        $this->assertSame('', $html);
        // No link tags — browsers may still use a passive root favicon.ico.
        $this->assertStringNotContainsString('rel="icon"', $html);
        $this->assertStringNotContainsString('apple-touch-icon', $html);
    }

    /**
     * SPEC: when site_icon is unset, leave a manual root favicon.ico as a
     * passive browser fallback — do not invent icon link tags for it.
     */
    public function testRootFaviconIcoPassiveFallbackWhenNoSiteIcon(): void
    {
        AP_Options::update('site_icon', '0', $this->db);
        AP_Options::flushCache();
        $this->assertSame(0, AP_Options::siteIcon($this->db));

        // Even if an operator places favicon.ico at the document root, core must
        // not auto-discover it or emit a synthetic <link rel="icon" href="…">.
        $rootIco = $this->root . '/favicon.ico';
        $createdRootIco = false;
        if (!is_file($rootIco)) {
            $this->assertNotFalse(file_put_contents($rootIco, "\x00\x00\x01\x00"));
            $createdRootIco = true;
        }

        try {
            $tags = AP_Media::getSiteIconMetaTags($this->db);
            $this->assertSame([], $tags, 'No managed site_icon → no head icon tags');

            ob_start();
            AP_Media::printSiteIconTags($this->db);
            $html = (string) ob_get_clean();
            $this->assertSame('', $html);
            $this->assertStringNotContainsString('rel="icon"', $html);
            $this->assertStringNotContainsString('apple-touch-icon', $html);
            $this->assertStringNotContainsString('favicon.ico', $html);
            $this->assertStringNotContainsString('/favicon.ico', implode("\n", $tags));

            // Front-server configs must still serve real files (passive static path).
            $htaccess = (string) file_get_contents($this->root . '/.htaccess');
            $this->assertStringContainsString('REQUEST_FILENAME} !-f', $htaccess);
            $this->assertStringContainsString('favicon.ico', $htaccess);

            $apache = \AP_Rewrite::apacheRewriteBlock('/');
            $this->assertStringContainsString('REQUEST_FILENAME} !-f', $apache);
            $this->assertStringContainsString('favicon.ico', $apache);

            $nginx = \AP_Rewrite::nginxTryFilesSnippet();
            $this->assertStringContainsString('try_files $uri', $nginx);

            $nginxExample = (string) file_get_contents($this->root . '/docker/nginx.conf.example');
            $this->assertStringContainsString('try_files $uri', $nginxExample);
            $this->assertStringContainsString('favicon.ico', $nginxExample);

            // Source contract: no synthetic root link when site_icon is unset.
            $mediaSrc = (string) file_get_contents($this->root . '/ap-includes/class-ap-media.php');
            $this->assertStringContainsString('passive', strtolower($mediaSrc));
            $this->assertStringContainsString('favicon.ico', $mediaSrc);
            $this->assertStringContainsString(
                'Do not emit a synthetic /favicon.ico link tag',
                $mediaSrc
            );
        } finally {
            if ($createdRootIco && is_file($rootIco)) {
                @unlink($rootIco);
            }
        }
    }

    public function testSiteIconMetaTagsEmitLinkTagsWhenSet(): void
    {
        if (!AP_Media::imageEditorAvailable()) {
            $this->markTestSkipped('GD or Imagick required for site icon derivatives.');
        }

        $tmp = $this->writeTempPngSized('head-icon.png', 64, 64);
        $up = AP_Media::handleUpload([
            'name' => 'head-icon.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($tmp),
        ], [
            'test_mode' => true,
            'post_author' => $this->actorId,
            'skip_rate_limit' => true,
        ], $this->db);
        $this->assertTrue($up['ok'], $up['error']);
        $id = $up['id'];

        $gen = AP_Media::generateSiteIconSizes($id, $this->db);
        $this->assertTrue($gen['ok'], $gen['error']);

        AP_Options::update('site_icon', (string) $id, $this->db);
        AP_Options::flushCache();
        $this->assertSame($id, AP_Options::siteIcon($this->db));

        $tags = AP_Media::getSiteIconMetaTags($this->db);
        $this->assertNotSame([], $tags);

        $joined = implode("\n", $tags);
        $this->assertStringContainsString('rel="icon"', $joined);
        $this->assertStringContainsString('sizes="32x32"', $joined);
        $this->assertStringContainsString('sizes="192x192"', $joined);
        $this->assertStringContainsString('sizes="512x512"', $joined);
        $this->assertStringContainsString('rel="apple-touch-icon"', $joined);
        $this->assertStringContainsString('sizes="180x180"', $joined);
        $this->assertStringContainsString('site_icon-32', $joined);
        $this->assertStringContainsString('site_icon-180', $joined);
        $this->assertStringContainsString('type="image/png"', $joined);

        // ICO when generated.
        if (isset(AP_Media::getSiteIconSizes($id, $this->db)['ico'])) {
            $this->assertStringContainsString('sizes="any"', $joined);
            $this->assertStringContainsString('type="image/x-icon"', $joined);
            $this->assertStringContainsString('site_icon.ico', $joined);
        }

        ob_start();
        AP_Media::printSiteIconTags($this->db);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('rel="icon"', $html);
        $this->assertStringContainsString('apple-touch-icon', $html);
        $this->assertStringContainsString("\n", $html);

        // Remove icon → tags gone.
        AP_Options::update('site_icon', '0', $this->db);
        AP_Options::flushCache();
        $this->assertSame([], AP_Media::getSiteIconMetaTags($this->db));
        ob_start();
        AP_Media::printSiteIconTags($this->db);
        $this->assertSame('', (string) ob_get_clean());
    }

    public function testSiteIconMetaTagsFallbackToAttachmentUrlWithoutDerivatives(): void
    {
        $tmp = $this->writeTempPngSized('no-deriv-icon.png', 24, 24);
        $up = AP_Media::handleUpload([
            'name' => 'no-deriv-icon.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($tmp),
        ], [
            'test_mode' => true,
            'post_author' => $this->actorId,
            'skip_rate_limit' => true,
        ], $this->db);
        $this->assertTrue($up['ok'], $up['error']);
        $id = $up['id'];

        // Option set but no generateSiteIconSizes run.
        $this->assertSame([], AP_Media::getSiteIconSizes($id, $this->db));
        AP_Options::update('site_icon', (string) $id, $this->db);
        AP_Options::flushCache();

        $tags = AP_Media::getSiteIconMetaTags($this->db);
        $this->assertCount(1, $tags);
        $this->assertStringContainsString('rel="icon"', $tags[0]);
        $attUrl = AP_Media::getAttachmentUrl($id, $this->db);
        $this->assertNotSame('', $attUrl);
        $this->assertStringContainsString($attUrl, $tags[0]);
    }

    public function testRegisterSiteIconTagsHooksApHead(): void
    {
        require_once $this->root . '/ap-includes/hooks.php';
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Media::resetSiteIconTagsRegistration();

        // ap_head → printSiteIconTags() resolves DB via ap_db() / $GLOBALS['apdb'].
        $prevApdb = $GLOBALS['apdb'] ?? null;
        $GLOBALS['apdb'] = $this->db;

        try {
            AP_Options::update('site_icon', '0', $this->db);
            AP_Options::flushCache();

            AP_Media::registerSiteIconTags();
            // Idempotent.
            AP_Media::registerSiteIconTags();

            $this->assertTrue(function_exists('ap_has_action'));
            $this->assertNotFalse(ap_has_action('ap_head', [AP_Media::class, 'printSiteIconTags']));

            ob_start();
            ap_do_action('ap_head');
            $empty = (string) ob_get_clean();
            $this->assertStringNotContainsString('rel="icon"', $empty);

            if (!AP_Media::imageEditorAvailable()) {
                return;
            }

            $tmp = $this->writeTempPngSized('hook-icon.png', 40, 40);
            $up = AP_Media::handleUpload([
                'name' => 'hook-icon.png',
                'type' => 'image/png',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => (int) filesize($tmp),
            ], [
                'test_mode' => true,
                'post_author' => $this->actorId,
                'skip_rate_limit' => true,
            ], $this->db);
            $this->assertTrue($up['ok'], $up['error']);
            $id = $up['id'];
            $this->assertTrue(AP_Media::generateSiteIconSizes($id, $this->db)['ok']);
            AP_Options::update('site_icon', (string) $id, $this->db);
            AP_Options::flushCache();

            ob_start();
            ap_do_action('ap_head');
            $html = (string) ob_get_clean();
            $this->assertStringContainsString('rel="icon"', $html);
            $this->assertStringContainsString('apple-touch-icon', $html);
        } finally {
            if ($prevApdb instanceof AP_DB) {
                $GLOBALS['apdb'] = $prevApdb;
            } else {
                unset($GLOBALS['apdb']);
            }
            AP_Media::resetSiteIconTagsRegistration();
        }
    }

    public function testBootstrapRegistersSiteIconTags(): void
    {
        $bootstrap = (string) file_get_contents($this->root . '/ap-includes/bootstrap.php');
        $this->assertStringContainsString('registerSiteIconTags', $bootstrap);
        $media = (string) file_get_contents($this->root . '/ap-includes/class-ap-media.php');
        $this->assertStringContainsString('function printSiteIconTags', $media);
        $this->assertStringContainsString('function getSiteIconMetaTags', $media);
        $this->assertStringContainsString('ap_site_icon_meta_tags', $media);
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

    /**
     * Solid-color PNG at exact dimensions (requires GD for test fixture only).
     */
    private function writeTempPngSized(string $name, int $width, int $height): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return $this->writeTempPng($name);
        }
        $img = imagecreatetruecolor(max(1, $width), max(1, $height));
        $this->assertNotFalse($img);
        $bg = imagecolorallocate($img, 32, 96, 160);
        imagefilledrectangle($img, 0, 0, max(1, $width) - 1, max(1, $height) - 1, $bg);
        $path = sys_get_temp_dir() . '/ap-png-' . bin2hex(random_bytes(4)) . '-' . $name;
        imagepng($img, $path);
        imagedestroy($img);
        $this->assertFileExists($path);

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
