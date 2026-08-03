<?php

/**
 * Tests for AP_Avatar — local upload, Gravatar URLs, options, HTML.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\User;

use AP_Admin_User_Edit;
use AP_Avatar;
use AP_DB;
use AP_Media;
use AP_Migrator;
use AP_Nonce;
use AP_Options;
use AP_Post;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Avatar::class)]
final class AvatarTest extends TestCase
{
    private string $root;

    private static string $uploadsTmp = '';

    private AP_DB $db;

    public static function setUpBeforeClass(): void
    {
        self::$uploadsTmp = sys_get_temp_dir() . '/ap-avatar-test-' . bin2hex(random_bytes(6));
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
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-media.php';
        require_once $this->root . '/ap-includes/class-ap-avatar.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-includes/template-tags.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-user-edit.php';

        $this->emptyDir(self::$uploadsTmp);

        AP_Post::resetRegistry();
        AP_Roles::flushCache();
        AP_Options::flushCache();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Post::ensureBuiltins();
        AP_Roles::ensureDefaults($this->db);

        AP_Options::update(AP_Avatar::OPTION_SHOW, '1', $this->db);
        AP_Options::update(AP_Avatar::OPTION_DEFAULT, 'mystery', $this->db);
        AP_Options::update(AP_Avatar::OPTION_RATING, 'g', $this->db);
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
        AP_Roles::flushCache();
        AP_Options::flushCache();
        $this->emptyDir(self::$uploadsTmp);
    }

    public function testGravatarHashAndUrl(): void
    {
        $email = 'Test.User@Example.COM';
        $hash = AP_Avatar::gravatarHash($email);
        $this->assertSame(md5('test.user@example.com'), $hash);

        $url = AP_Avatar::gravatarUrl($email, 64, 'mystery', 'g');
        $this->assertStringStartsWith('https://secure.gravatar.com/avatar/', $url);
        $this->assertStringContainsString($hash, $url);
        $this->assertStringContainsString('s=64', $url);
        $this->assertStringContainsString('d=mp', $url);
        $this->assertStringContainsString('r=g', $url);
    }

    public function testShowAvatarsOptionGatesHtml(): void
    {
        AP_Options::update(AP_Avatar::OPTION_SHOW, '0', $this->db);
        $this->assertFalse(AP_Avatar::isEnabled($this->db));
        $this->assertSame('', AP_Avatar::getUrl('guest@example.test', 48, [], $this->db));
        $this->assertSame('', AP_Avatar::getHtml('guest@example.test', 48, [], $this->db));

        // force_display bypasses the option for admin previews.
        $html = AP_Avatar::getHtml('guest@example.test', 48, ['force_display' => true], $this->db);
        $this->assertStringContainsString('<img', $html);
    }

    public function testEmailFallsBackToGravatar(): void
    {
        $data = AP_Avatar::getData('alice@example.test', 96, [], $this->db);
        $this->assertTrue($data['found']);
        $this->assertSame('gravatar', $data['source']);
        $this->assertStringContainsString('gravatar.com/avatar/', $data['url']);

        $html = AP_Avatar::getHtml('alice@example.test', 96, ['alt' => 'Alice'], $this->db);
        $this->assertStringContainsString('class="', $html);
        $this->assertStringContainsString('avatar-96', $html);
        $this->assertStringContainsString('alt="Alice"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
    }

    public function testBlankDefaultUsesDataUri(): void
    {
        AP_Options::update(AP_Avatar::OPTION_DEFAULT, 'blank', $this->db);
        $data = AP_Avatar::getData('nobody@example.test', 32, [], $this->db);
        $this->assertTrue($data['found']);
        $this->assertSame('blank', $data['source']);
        $this->assertStringStartsWith('data:image/gif;base64,', $data['url']);
    }

    public function testLocalAvatarUploadAndReplace(): void
    {
        $created = AP_User::create([
            'user_login' => 'avatar_user',
            'user_email' => 'avatar@example.test',
            'password' => 'secret-pass-99',
            'display_name' => 'Avatar User',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($created['ok']);
        $userId = $created['id'];

        $tmp = $this->writeTempPng('avatar1.png');
        $result = AP_Avatar::upload($userId, [
            'name' => 'avatar1.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp) ?: 0,
        ], $this->db);
        $this->assertTrue($result['ok'], $result['error']);
        $this->assertGreaterThan(0, $result['id']);
        $this->assertSame($result['id'], AP_Avatar::getLocalAttachmentId($userId, $this->db));

        $data = AP_Avatar::getData($userId, 64, [], $this->db);
        $this->assertSame('local', $data['source']);
        $this->assertStringContainsString('https://example.test/ap-content/uploads/', $data['url']);

        // Procedural helpers.
        $this->assertTrue(function_exists('ap_get_avatar'));
        $this->assertTrue(function_exists('ap_get_avatar_url'));
        $this->assertTrue(function_exists('ap_upload_user_avatar'));
        $html = ap_get_avatar($userId, 64, '', '', [], $this->db);
        $this->assertStringContainsString('<img', $html);
        $this->assertSame($data['url'], ap_get_avatar_url($userId, 64, [], $this->db));

        // Replace with a second upload.
        $firstId = $result['id'];
        $tmp2 = $this->writeTempPng('avatar2.png');
        $result2 = AP_Avatar::upload($userId, [
            'name' => 'avatar2.png',
            'type' => 'image/png',
            'tmp_name' => $tmp2,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp2) ?: 0,
        ], $this->db);
        $this->assertTrue($result2['ok'], $result2['error']);
        $this->assertNotSame($firstId, $result2['id']);
        $this->assertNull(AP_Post::get($firstId, $this->db));

        // Delete local avatar → Gravatar again.
        $this->assertTrue(AP_Avatar::deleteLocal($userId, true, $this->db));
        $this->assertSame(0, AP_Avatar::getLocalAttachmentId($userId, $this->db));
        $after = AP_Avatar::getData($userId, 64, [], $this->db);
        $this->assertSame('gravatar', $after['source']);
    }

    public function testRejectsNonImageExtension(): void
    {
        $created = AP_User::create([
            'user_login' => 'bad_avatar',
            'user_email' => 'bad@example.test',
            'password' => 'secret-pass-99',
        ], $this->db);
        $userId = $created['id'];

        $tmp = tempnam(sys_get_temp_dir(), 'apav');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, '<?php echo "nope";');
        $result = AP_Avatar::upload($userId, [
            'name' => 'evil.php',
            'type' => 'application/x-php',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp) ?: 0,
        ], $this->db);
        @unlink($tmp);
        $this->assertFalse($result['ok']);
        $this->assertSame(0, AP_Avatar::getLocalAttachmentId($userId, $this->db));
    }

    public function testAdminFormIncludesAvatarAndMultipart(): void
    {
        $created = AP_User::create([
            'user_login' => 'form_user',
            'user_email' => 'form@example.test',
            'password' => 'secret-pass-99',
            'role' => 'author',
        ], $this->db);
        $user = $created['user'];
        $this->assertInstanceOf(AP_User::class, $user);

        $html = AP_Admin_User_Edit::renderForm($user, 'profile', $user->ID, [], $this->db);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="ap_avatar"', $html);
        $this->assertStringContainsString('ap-avatar-fieldset', $html);
        $this->assertStringContainsString('<img', $html);

        // Create form has no avatar upload (user does not exist yet).
        $createHtml = AP_Admin_User_Edit::renderForm(null, 'create', 1, [], $this->db);
        $this->assertStringNotContainsString('name="ap_avatar"', $createHtml);
    }

    public function testAdminSaveUploadAndRemoveAvatar(): void
    {
        $admin = AP_User::create([
            'user_login' => 'admin_av',
            'user_email' => 'admin_av@example.test',
            'password' => 'secret-pass-99',
            'role' => 'administrator',
        ], $this->db);
        $target = AP_User::create([
            'user_login' => 'target_av',
            'user_email' => 'target_av@example.test',
            'password' => 'secret-pass-99',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($admin['ok'] && $target['ok']);
        $adminId = $admin['id'];
        $targetId = $target['id'];

        $nonce = AP_Nonce::create('update-user-' . $targetId, $adminId);
        $tmp = $this->writeTempPng('admin-avatar.png');
        $save = AP_Admin_User_Edit::save(
            [
                'user_ID' => $targetId,
                'user_email' => 'target_av@example.test',
                'user_url' => '',
                'display_name' => 'Target',
                'first_name' => '',
                'last_name' => '',
                'nickname' => '',
                'description' => '',
                '_ap_nonce' => $nonce,
            ],
            $adminId,
            'update',
            $this->db,
            [
                'ap_avatar' => [
                    'name' => 'admin-avatar.png',
                    'type' => 'image/png',
                    'tmp_name' => $tmp,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($tmp) ?: 0,
                ],
            ]
        );
        $this->assertTrue($save['ok'], implode('; ', $save['errors']));
        $this->assertGreaterThan(0, AP_Avatar::getLocalAttachmentId($targetId, $this->db));

        $nonce2 = AP_Nonce::create('update-user-' . $targetId, $adminId);
        $remove = AP_Admin_User_Edit::save(
            [
                'user_ID' => $targetId,
                'user_email' => 'target_av@example.test',
                'user_url' => '',
                'display_name' => 'Target',
                'first_name' => '',
                'last_name' => '',
                'nickname' => '',
                'description' => '',
                'remove_avatar' => '1',
                '_ap_nonce' => $nonce2,
            ],
            $adminId,
            'update',
            $this->db,
            []
        );
        $this->assertTrue($remove['ok'], implode('; ', $remove['errors']));
        $this->assertSame(0, AP_Avatar::getLocalAttachmentId($targetId, $this->db));
    }

    public function testDeleteUserRemovesLocalAvatar(): void
    {
        $created = AP_User::create([
            'user_login' => 'del_av',
            'user_email' => 'del_av@example.test',
            'password' => 'secret-pass-99',
        ], $this->db);
        $userId = $created['id'];
        $tmp = $this->writeTempPng('del.png');
        $up = AP_Avatar::upload($userId, [
            'name' => 'del.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp) ?: 0,
        ], $this->db);
        $this->assertTrue($up['ok']);
        $attachmentId = $up['id'];

        $this->assertTrue(AP_User::delete($userId, $this->db));
        $this->assertNull(AP_Post::get($attachmentId, $this->db));
    }

    public function testAuthorAvatarTemplateTag(): void
    {
        $created = AP_User::create([
            'user_login' => 'author_av',
            'user_email' => 'author_av@example.test',
            'password' => 'secret-pass-99',
            'display_name' => 'Author Av',
        ], $this->db);
        $userId = $created['id'];

        $postId = AP_Post::insert([
            'post_title' => 'With Author',
            'post_content' => 'Hello',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => $userId,
        ], $this->db);
        $this->assertGreaterThan(0, $postId);
        $post = AP_Post::get($postId, $this->db);
        $this->assertInstanceOf(AP_Post::class, $post);

        $this->assertTrue(function_exists('ap_get_the_author_avatar'));
        $html = ap_get_the_author_avatar(48, $post, [], $this->db);
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('avatar-48', $html);
    }

    public function testCommentLikeObjectUsesEmail(): void
    {
        $comment = (object) [
            'user_id' => 0,
            'comment_author' => 'Guest',
            'comment_author_email' => 'guest@example.test',
        ];
        $data = AP_Avatar::getData($comment, 40, [], $this->db);
        $this->assertSame('gravatar', $data['source']);
        $this->assertStringContainsString(AP_Avatar::gravatarHash('guest@example.test'), $data['url']);
    }

    public function testMysteryDataUriHelper(): void
    {
        $uri = AP_Avatar::mysteryDataUri(96);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function writeTempPng(string $name): string
    {
        // Minimal valid 1×1 PNG.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );
        $this->assertNotFalse($png);
        $path = self::$uploadsTmp . '/_src_' . $name;
        file_put_contents($path, $png);

        return $path;
    }

    private function emptyDir(string $dir): void
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
