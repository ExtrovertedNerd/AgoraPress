<?php

/**
 * Plugins screen "Upload plugin (.zip)" form: POST, multipart, nonce, capability.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Admin_Plugin_Upload;
use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Plugin;
use AP_Plugin_Install_Error;
use AP_Plugin_Install_Result;
use AP_Plugin_Installer;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(AP_Admin_Plugin_Upload::class)]
final class AdminPluginUploadTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private string $workDir;

    private string $contentDir;

    private string $pluginsRoot;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/class-ap-plugin-installer.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-plugin-upload.php';

        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('p', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('u', 32));
        }

        ap_reset_hooks();
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
        AP_Roles::ensureDefaults($this->db);

        $this->workDir = sys_get_temp_dir() . '/ap-plugin-upload-form-' . uniqid('', true);
        $this->contentDir = $this->workDir . '/content';
        $this->pluginsRoot = $this->contentDir . '/plugins';
        $this->assertTrue(mkdir($this->pluginsRoot, 0700, true));
    }

    protected function tearDown(): void
    {
        ap_reset_hooks();
        if (class_exists(AP_Plugin::class, false)) {
            AP_Plugin::reset();
        }
        AP_Roles::flushCache();
        AP_Options::flushCache();
        unset($GLOBALS['apdb']);
        $this->removeDir($this->workDir);
    }

    public function testFormIsPostMultipartWithNonceForInstallersOnly(): void
    {
        $adminId = $this->createUser('uploadadmin', 'administrator');
        $editorId = $this->createUser('uploadeditor', 'editor');

        $html = AP_Admin_Plugin_Upload::render($adminId, $this->db);

        $this->assertStringContainsString(AP_Admin_Plugin_Upload::HEADING, $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="_ap_nonce"', $html);
        $this->assertStringContainsString('name="ap_plugin_action"', $html);
        $this->assertStringContainsString('value="upload"', $html);
        $this->assertStringContainsString('name="pluginzip"', $html);
        $this->assertStringContainsString('type="file"', $html);
        $this->assertStringContainsString('20 MB', $html);
        $this->assertStringNotContainsString('name="overwrite"', $html);
        $this->assertStringContainsString('name="' . AP_Admin_Plugin_Upload::ACTIVATE_FIELD . '"', $html);
        $this->assertStringContainsString('id="ap-plugin-activate"', $html);
        $this->assertStringContainsString('value="1"', $html);
        $this->assertStringContainsString(AP_Admin_Plugin_Upload::ACTIVATE_LABEL, $html);
        $this->assertDoesNotMatchRegularExpression('/id="ap-plugin-activate"[^>]*checked/', $html);
        $this->assertStringNotContainsString('id="ap-plugin-overwrite-activate"', $html);
        $this->assertStringNotContainsString('ap-plugin-overwrite', $html);
        $this->assertSame(1, preg_match('/name="_ap_nonce" value="([^"]+)"/', $html, $match));
        $this->assertTrue(ap_check_nonce((string) $match[1], AP_Admin_Plugin_Upload::NONCE_ACTION, $adminId));
        $this->assertFalse(ap_check_nonce((string) $match[1], 'other-action', $adminId));

        $this->assertSame('', AP_Admin_Plugin_Upload::render($editorId, $this->db));
        $this->assertSame('', AP_Admin_Plugin_Upload::render(0, $this->db));
    }

    public function testGetDoesNotInstall(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('getadmin', 'administrator');
        $zip = $this->pluginZip('1.0.0');
        $outcome = $this->submit(
            'GET',
            $this->uploadPost($adminId),
            $this->fileBag($zip),
            $this->multipartServer(),
            $adminId,
            true
        );

        $this->assertFalse($outcome['handled']);
        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_IGNORED, $outcome['status']);
        $this->assertNull($outcome['result']);
        $this->assertPluginAbsent();
    }

    public function testMissingNonceIsRefused(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('noncenadmin', 'administrator');
        $zip = $this->pluginZip('1.0.0');
        $post = $this->uploadPost($adminId);
        unset($post['_ap_nonce']);

        $missing = $this->submit('POST', $post, $this->fileBag($zip), $this->multipartServer(), $adminId, true);
        $this->assertTrue($missing['handled']);
        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_BAD_NONCE, $missing['status']);
        $this->assertSame(AP_Admin_Plugin_Upload::NOTICE_BAD_NONCE, $missing['notice']);
        $this->assertSame('error', $missing['notice_type']);
        $this->assertNull($missing['result']);
        $this->assertNull($missing['error']);
        $this->assertPluginAbsent();

        $post['_ap_nonce'] = ap_create_nonce('other-action', $adminId);
        $wrong = $this->submit('POST', $post, $this->fileBag($zip), $this->multipartServer(), $adminId, true);
        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_BAD_NONCE, $wrong['status']);
        $this->assertPluginAbsent();
    }

    public function testNonAdminIsRefusedBeforeNonce(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $editorId = $this->createUser('capeditor', 'editor');
        $zip = $this->pluginZip('1.0.0');
        $post = [
            'ap_plugin_action' => 'upload',
            'overwrite' => '1',
        ];

        $noNonce = $this->submit('POST', $post, $this->fileBag($zip), $this->multipartServer(), $editorId, true);
        $this->assertTrue($noNonce['handled']);
        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_FORBIDDEN, $noNonce['status']);
        $this->assertSame(AP_Admin_Plugin_Upload::NOTICE_FORBIDDEN, $noNonce['notice']);
        $this->assertNull($noNonce['result']);
        $this->assertNull($noNonce['error']);
        $this->assertPluginAbsent();

        $withNonce = $this->submit(
            'POST',
            $this->uploadPost($editorId, ['overwrite' => '1']),
            $this->fileBag($zip),
            $this->multipartServer(),
            $editorId,
            true
        );
        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_FORBIDDEN, $withNonce['status']);
        $this->assertPluginAbsent();
        $this->assertFalse(AP_Roles::userCan($editorId, 'install_plugins', null, $this->db));
    }

    public function testUrlEncodedPostIsRefused(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('urladmin', 'administrator');
        $zip = $this->pluginZip('1.0.0');
        $outcome = $this->submit(
            'POST',
            $this->uploadPost($adminId),
            $this->fileBag($zip),
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_NOT_MULTIPART, $outcome['status']);
        $this->assertSame(AP_Admin_Plugin_Upload::NOTICE_NOT_MULTIPART, $outcome['notice']);
        $this->assertNull($outcome['result']);
        $this->assertPluginAbsent();
    }

    public function testForgedUploadPathIsRefused(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('forgeadmin', 'administrator');
        $zip = $this->pluginZip('1.0.0');
        $outcome = $this->submit(
            'POST',
            $this->uploadPost($adminId),
            $this->fileBag($zip),
            $this->multipartServer(),
            $adminId,
            false
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_BAD_UPLOAD, $outcome['status']);
        $this->assertSame('Invalid upload source.', $outcome['notice']);
        $this->assertNull($outcome['result']);
        $this->assertPluginAbsent();
    }

    public function testMissingFileIsRefused(): void
    {
        $adminId = $this->createUser('emptyadmin', 'administrator');
        $outcome = $this->submit(
            'POST',
            $this->uploadPost($adminId),
            [],
            $this->multipartServer(),
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_BAD_UPLOAD, $outcome['status']);
        $this->assertSame('No plugin file was uploaded.', $outcome['notice']);
        $this->assertNull($outcome['redirect_query']);
    }

    public function testValidMultipartUploadInstallsNewPlugin(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('okadmin', 'administrator');
        $zip = $this->pluginZip('1.2.0');
        $outcome = $this->submit(
            'POST',
            $this->uploadPost($adminId),
            $this->fileBag($zip),
            ['CONTENT_TYPE' => 'multipart/form-data; boundary=----ap'],
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_INSTALLED, $outcome['status']);
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $outcome['result']);
        $this->assertSame('demo-notes', $outcome['result']->slug());
        $this->assertSame('Demo Notes', $outcome['result']->name());
        $this->assertSame('1.2.0', $outcome['result']->version());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_INSTALLED, $outcome['result']->action());
        $this->assertFalse($outcome['result']->activated());
        $this->assertSame(
            [
                'message' => 'plugin_installed',
                'plugin' => 'demo-notes/demo-notes.php',
            ],
            $outcome['redirect_query']
        );
        $this->assertNull($outcome['confirmation']);
        $this->assertDirectoryDoesNotExist($this->contentDir . '/' . AP_Admin_Plugin_Upload::PENDING_DIR);
        $this->assertFileExists($this->pluginsRoot . '/demo-notes/demo-notes.php');
        $this->assertStringContainsString('Version: 1.2.0', (string) file_get_contents(
            $this->pluginsRoot . '/demo-notes/demo-notes.php'
        ));
    }

    public function testPostedOverwriteDoesNotReplaceExistingPlugin(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('keepadmin', 'administrator');
        $this->installDemo('1.0.0');

        $outcome = $this->submit(
            'POST',
            $this->uploadPost($adminId, ['overwrite' => '1', 'activate' => '1']),
            $this->fileBag($this->pluginZip('2.0.0', 'Demo Notes Pro')),
            $this->multipartServer(),
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_NEEDS_CONFIRMATION, $outcome['status']);
        $this->assertNull($outcome['result']);
        $this->assertNull($outcome['error']);
        $this->assertNull($outcome['redirect_query']);
        $this->assertSame('', $outcome['notice']);
        $confirmation = $outcome['confirmation'];
        $this->assertIsArray($confirmation);
        $this->assertSame('demo-notes', $confirmation['slug']);
        $this->assertSame('Demo Notes Pro', $confirmation['name']);
        $this->assertSame('2.0.0', $confirmation['version']);
        $this->assertSame('Demo Notes', $confirmation['current_name']);
        $this->assertSame('1.0.0', $confirmation['current_version']);
        $this->assertSame('demo-notes/demo-notes.php', $confirmation['plugin']);
        $this->assertTrue($confirmation['activate']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $confirmation['token']);
        $this->assertPluginVersion('1.0.0');
        $this->assertPendingHeld($confirmation['token']);

        $html = AP_Admin_Plugin_Upload::render($adminId, $this->db, $confirmation);
        $this->assertStringContainsString(AP_Admin_Plugin_Upload::CONFIRM_HEADING, $html);
        $this->assertStringContainsString('ap-plugin-overwrite-name">Demo Notes Pro<', $html);
        $this->assertStringContainsString('ap-plugin-overwrite-current-name">Demo Notes<', $html);
        $this->assertStringContainsString('ap-plugin-overwrite-current">1.0.0<', $html);
        $this->assertStringContainsString('ap-plugin-overwrite-new">2.0.0<', $html);
        $this->assertStringContainsString('value="confirm_overwrite"', $html);
        $this->assertStringContainsString('value="cancel_overwrite"', $html);
        $this->assertStringContainsString('name="plugin_overwrite_token"', $html);
        $this->assertStringNotContainsString('name="overwrite"', $html);
        $this->assertMatchesRegularExpression(
            '/id="ap-plugin-overwrite-activate"[^>]*checked="checked"/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression('/id="ap-plugin-activate"[^>]*checked/', $html);
        $this->assertSame(2, substr_count($html, AP_Admin_Plugin_Upload::ACTIVATE_LABEL));
        $this->assertGreaterThanOrEqual(1, preg_match_all(
            '/name="_ap_nonce" value="([^"]+)"/',
            $html,
            $nonces
        ));
        $overwriteNonces = 0;
        $uploadNonces = 0;
        foreach ($nonces[1] as $nonce) {
            $isOverwrite = ap_check_nonce($nonce, AP_Admin_Plugin_Upload::NONCE_OVERWRITE, $adminId);
            $isUpload = ap_check_nonce($nonce, AP_Admin_Plugin_Upload::NONCE_ACTION, $adminId);
            $this->assertTrue($isOverwrite || $isUpload);
            $this->assertFalse($isOverwrite && $isUpload);
            $overwriteNonces += $isOverwrite ? 1 : 0;
            $uploadNonces += $isUpload ? 1 : 0;
        }
        $this->assertSame(2, $overwriteNonces);
        $this->assertSame(1, $uploadNonces);
    }

    public function testConfirmOverwriteReplacesShownVersion(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('replaceadmin', 'administrator');
        $this->installDemo('1.0.0');
        $held = $this->holdUpgrade($adminId, '2.0.0');
        $token = $held['confirmation']['token'];

        $outcome = $this->submit(
            'POST',
            $this->confirmPost($adminId, $token, AP_Admin_Plugin_Upload::POST_CONFIRM),
            $this->fileBag($this->pluginZip('8.8.8')),
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_REPLACED, $outcome['status']);
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $outcome['result']);
        $this->assertSame(AP_Plugin_Install_Result::ACTION_UPGRADED, $outcome['result']->action());
        $this->assertSame('2.0.0', $outcome['result']->version());
        $this->assertSame('1.0.0', $outcome['result']->previousVersion());
        $this->assertFalse($outcome['result']->activated());
        $this->assertNotNull($outcome['result']->backupPath());
        $this->assertStringContainsString('.ap-plugin-backups', (string) $outcome['result']->backupPath());
        $this->assertSame(
            [
                'message' => 'plugin_replaced',
                'plugin' => 'demo-notes/demo-notes.php',
                'plugin_name' => 'Demo Notes',
                'plugin_from' => '1.0.0',
                'plugin_to' => '2.0.0',
            ],
            $outcome['redirect_query']
        );
        $this->assertNull($outcome['confirmation']);
        $this->assertPluginVersion('2.0.0');
        $this->assertDirectoryDoesNotExist($this->pendingDir($token));
    }

    public function testCancelOverwriteLeavesInstalledVersion(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('canceladmin', 'administrator');
        $this->installDemo('1.0.0');
        $held = $this->holdUpgrade($adminId, '2.0.0');
        $token = $held['confirmation']['token'];

        $outcome = $this->submit(
            'POST',
            $this->confirmPost($adminId, $token, AP_Admin_Plugin_Upload::POST_CANCEL),
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_CANCELLED, $outcome['status']);
        $this->assertSame(AP_Admin_Plugin_Upload::NOTICE_CANCELLED, $outcome['notice']);
        $this->assertSame('info', $outcome['notice_type']);
        $this->assertNull($outcome['result']);
        $this->assertNull($outcome['redirect_query']);
        $this->assertPluginVersion('1.0.0');
        $this->assertDirectoryDoesNotExist($this->pendingDir($token));
    }

    public function testConfirmWithoutNonceDoesNotReplace(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('confnonce', 'administrator');
        $this->installDemo('1.0.0');
        $held = $this->holdUpgrade($adminId, '2.0.0');
        $token = $held['confirmation']['token'];
        $post = $this->confirmPost($adminId, $token, AP_Admin_Plugin_Upload::POST_CONFIRM);
        $post['_ap_nonce'] = ap_create_nonce(AP_Admin_Plugin_Upload::NONCE_ACTION, $adminId);

        $wrong = $this->submit(
            'POST',
            $post,
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $adminId,
            true
        );
        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_BAD_NONCE, $wrong['status']);
        $this->assertPluginVersion('1.0.0');
        $this->assertFileExists($this->pendingJson($token));

        unset($post['_ap_nonce']);
        $missing = $this->submit(
            'POST',
            $post,
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $adminId,
            true
        );
        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_BAD_NONCE, $missing['status']);
        $this->assertPluginVersion('1.0.0');
        $this->assertFileExists($this->pendingJson($token));
    }

    public function testNonAdminCannotConfirmOverwrite(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('ownadmin', 'administrator');
        $editorId = $this->createUser('confeditor', 'editor');
        $this->installDemo('1.0.0');
        $held = $this->holdUpgrade($adminId, '2.0.0');
        $token = $held['confirmation']['token'];

        $outcome = $this->submit(
            'POST',
            [
                'ap_plugin_action' => AP_Admin_Plugin_Upload::POST_CONFIRM,
                AP_Admin_Plugin_Upload::TOKEN_FIELD => $token,
            ],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $editorId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_FORBIDDEN, $outcome['status']);
        $this->assertSame(AP_Admin_Plugin_Upload::NOTICE_FORBIDDEN, $outcome['notice']);
        $this->assertPluginVersion('1.0.0');
        $this->assertFileExists($this->pendingJson($token));
        $this->assertSame('', AP_Admin_Plugin_Upload::render($editorId, $this->db, $held['confirmation']));
    }

    public function testConfirmTokenFromAnotherUserDoesNotReplace(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $ownerId = $this->createUser('tokenowner', 'administrator');
        $otherId = $this->createUser('tokenother', 'administrator');
        $this->installDemo('1.0.0');
        $held = $this->holdUpgrade($ownerId, '2.0.0');
        $token = $held['confirmation']['token'];

        $outcome = $this->submit(
            'POST',
            $this->confirmPost($otherId, $token, AP_Admin_Plugin_Upload::POST_CONFIRM),
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $otherId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_BAD_CONFIRMATION, $outcome['status']);
        $this->assertSame(AP_Admin_Plugin_Upload::NOTICE_CONFIRM_INVALID, $outcome['notice']);
        $this->assertPluginVersion('1.0.0');
        $this->assertFileExists($this->pendingJson($token));
    }

    public function testTraversalTokenDoesNotReplace(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('slipadmin', 'administrator');
        $this->installDemo('1.0.0');

        $outcome = $this->submit(
            'POST',
            $this->confirmPost($adminId, '../' . $this->pluginsRoot, AP_Admin_Plugin_Upload::POST_CONFIRM),
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_BAD_CONFIRMATION, $outcome['status']);
        $this->assertSame(AP_Admin_Plugin_Upload::NOTICE_CONFIRM_INVALID, $outcome['notice']);
        $this->assertPluginVersion('1.0.0');
        $this->assertDirectoryDoesNotExist($this->contentDir . '/' . AP_Admin_Plugin_Upload::PENDING_DIR);
    }

    public function testExpiredConfirmationDoesNotReplace(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('oldadmin', 'administrator');
        $this->installDemo('1.0.0');
        $held = $this->holdUpgrade($adminId, '2.0.0');
        $token = $held['confirmation']['token'];
        $jsonPath = $this->pendingJson($token);
        $record = json_decode((string) file_get_contents($jsonPath), true);
        $this->assertIsArray($record);
        $record['created'] = time() - AP_Admin_Plugin_Upload::PENDING_SECONDS - 5;
        $this->assertNotFalse(file_put_contents($jsonPath, json_encode($record)));

        $outcome = $this->submit(
            'POST',
            $this->confirmPost($adminId, $token, AP_Admin_Plugin_Upload::POST_CONFIRM),
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_BAD_CONFIRMATION, $outcome['status']);
        $this->assertSame(AP_Admin_Plugin_Upload::NOTICE_CONFIRM_INVALID, $outcome['notice']);
        $this->assertPluginVersion('1.0.0');
        $this->assertDirectoryDoesNotExist($this->pendingDir($token));
    }

    public function testSwappedZipDoesNotReplaceShownVersion(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('swapadmin', 'administrator');
        $this->installDemo('1.0.0');
        $held = $this->holdUpgrade($adminId, '2.0.0');
        $token = $held['confirmation']['token'];
        $this->assertTrue(copy($this->pluginZip('3.0.0'), $this->pendingDir($token) . '/package.zip'));

        $outcome = $this->submit(
            'POST',
            $this->confirmPost($adminId, $token, AP_Admin_Plugin_Upload::POST_CONFIRM),
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_BAD_CONFIRMATION, $outcome['status']);
        $this->assertSame(AP_Admin_Plugin_Upload::NOTICE_PACKAGE_MISMATCH, $outcome['notice']);
        $this->assertPluginVersion('1.0.0');
        $this->assertDirectoryDoesNotExist($this->pendingDir($token));
    }

    public function testInstalledVersionChangeBlocksOverwrite(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('driftadmin', 'administrator');
        $this->installDemo('1.0.0');
        $held = $this->holdUpgrade($adminId, '2.0.0');
        $token = $held['confirmation']['token'];
        $php = "<?php\n/**\n * Plugin Name: Demo Notes\n * Version: 9.9.9\n */\n";
        $this->assertNotFalse(file_put_contents($this->pluginsRoot . '/demo-notes/demo-notes.php', $php));

        $outcome = $this->submit(
            'POST',
            $this->confirmPost($adminId, $token, AP_Admin_Plugin_Upload::POST_CONFIRM),
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_BAD_CONFIRMATION, $outcome['status']);
        $this->assertSame(AP_Admin_Plugin_Upload::NOTICE_CONFIRM_CHANGED, $outcome['notice']);
        $this->assertPluginVersion('9.9.9');
        $this->assertDirectoryDoesNotExist($this->pendingDir($token));
    }

    public function testNewerUploadReplacesTheHeldConfirmation(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('againadmin', 'administrator');
        $this->installDemo('1.0.0');
        $first = $this->holdUpgrade($adminId, '2.0.0');
        $second = $this->holdUpgrade($adminId, '2.1.0');
        $this->assertNotSame($first['confirmation']['token'], $second['confirmation']['token']);
        $this->assertSame('2.1.0', $second['confirmation']['version']);
        $this->assertSame('1.0.0', $second['confirmation']['current_version']);
        $this->assertDirectoryDoesNotExist($this->pendingDir($first['confirmation']['token']));

        $stale = $this->submit(
            'POST',
            $this->confirmPost($adminId, $first['confirmation']['token'], AP_Admin_Plugin_Upload::POST_CONFIRM),
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $adminId,
            true
        );
        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_BAD_CONFIRMATION, $stale['status']);
        $this->assertPluginVersion('1.0.0');

        $outcome = $this->submit(
            'POST',
            $this->confirmPost($adminId, $second['confirmation']['token'], AP_Admin_Plugin_Upload::POST_CONFIRM),
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $adminId,
            true
        );
        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_REPLACED, $outcome['status']);
        $this->assertPluginVersion('2.1.0');
    }

    public function testConfirmationMarkupEscapesHeaderText(): void
    {
        $adminId = $this->createUser('markupadmin', 'administrator');
        $token = bin2hex(random_bytes(16));
        $html = AP_Admin_Plugin_Upload::render($adminId, $this->db, [
            'token' => $token,
            'slug' => 'demo-notes',
            'name' => 'Demo <script>Notes',
            'version' => '2<b>0',
            'current_name' => 'Old <b>Name</b>',
            'current_version' => '1.0.0',
            'plugin' => 'demo-notes/demo-notes.php',
        ]);

        $this->assertStringContainsString('Demo &lt;script&gt;Notes', $html);
        $this->assertStringContainsString('Old &lt;b&gt;Name&lt;/b&gt;', $html);
        $this->assertStringContainsString('ap-plugin-overwrite-current">1.0.0<', $html);
        $this->assertStringContainsString('ap-plugin-overwrite-new">2&lt;b&gt;0<', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('value="' . $token . '"', $html);

        $bad = AP_Admin_Plugin_Upload::render($adminId, $this->db, [
            'token' => '../etc/passwd',
            'slug' => 'demo-notes',
            'name' => 'Demo Notes',
            'version' => '2.0.0',
            'current_name' => 'Demo Notes',
            'current_version' => '1.0.0',
            'plugin' => 'demo-notes/demo-notes.php',
        ]);
        $this->assertStringNotContainsString('ap-plugin-overwrite-current', $bad);
        $this->assertStringNotContainsString('../etc/passwd', $bad);
        $this->assertStringContainsString(AP_Admin_Plugin_Upload::HEADING, $bad);

        $unknown = AP_Admin_Plugin_Upload::render($adminId, $this->db, [
            'token' => $token,
            'slug' => 'demo-notes',
            'name' => 'Demo Notes',
            'version' => '2.0.0',
            'current_name' => 'Demo Notes',
            'current_version' => '',
            'plugin' => 'demo-notes/demo-notes.php',
        ]);
        $this->assertStringContainsString('ap-plugin-overwrite-current">Unknown<', $unknown);
        $this->assertStringNotContainsString('ap-plugin-overwrite-same', $unknown);

        $same = AP_Admin_Plugin_Upload::render($adminId, $this->db, [
            'token' => $token,
            'slug' => 'demo-notes',
            'name' => 'Demo Notes',
            'version' => '1.0.0',
            'current_name' => 'Demo Notes',
            'current_version' => '1.0.0',
            'plugin' => 'demo-notes/demo-notes.php',
        ]);
        $this->assertStringContainsString('ap-plugin-overwrite-same', $same);
        $this->assertStringNotContainsString('ap-plugin-overwrite-current-name', $same);
    }

    public function testNonZipIsRejectedByInstaller(): void
    {
        $adminId = $this->createUser('textadmin', 'administrator');
        $path = $this->workDir . '/notes.zip';
        file_put_contents($path, "this is not a zip\n");

        $outcome = $this->submit(
            'POST',
            $this->uploadPost($adminId),
            $this->fileBag($path),
            $this->multipartServer(),
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_REJECTED, $outcome['status']);
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $outcome['error']);
        $this->assertSame(AP_Plugin_Install_Error::NOT_ZIP, $outcome['error']->code());
        $this->assertSame('error', $outcome['notice_type']);
        $this->assertSame(
            AP_Admin_Plugin_Upload::ERROR_NOTICES[AP_Plugin_Install_Error::NOT_ZIP],
            $outcome['notice']
        );
        $this->assertSame(
            AP_Admin_Plugin_Upload::noticeForError($outcome['error']),
            $outcome['notice']
        );
        $this->assertNotSame($outcome['error']->message(), $outcome['notice']);
        $this->assertNull($outcome['redirect_query']);
        $this->assertPluginAbsent();
    }

    public function testActivateCheckboxActivatesANewPlugin(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('actadmin', 'administrator');
        $this->useLivePluginDirectory();

        $outcome = $this->submit(
            'POST',
            $this->uploadPost($adminId, [AP_Admin_Plugin_Upload::ACTIVATE_FIELD => '1']),
            $this->fileBag($this->pluginZip('1.2.0')),
            $this->multipartServer(),
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_INSTALLED, $outcome['status']);
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $outcome['result']);
        $this->assertTrue($outcome['result']->activated());
        $this->assertIsArray($outcome['redirect_query']);
        $this->assertSame('1', $outcome['redirect_query']['activated']);
        $this->assertSame(
            'Plugin installed and activated: demo-notes/demo-notes.php.',
            AP_Admin_Plugin_Upload::successNotice($outcome['redirect_query'])
        );
        $this->assertTrue(AP_Plugin::isActive('demo-notes/demo-notes.php', $this->db));
        $this->assertFileExists($this->pluginsRoot . '/demo-notes/demo-notes.php');
    }

    public function testOnlyTheCheckboxValueActivates(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('looseadmin', 'administrator');
        $this->useLivePluginDirectory();

        $outcome = $this->submit(
            'POST',
            $this->uploadPost($adminId, [AP_Admin_Plugin_Upload::ACTIVATE_FIELD => 'on']),
            $this->fileBag($this->pluginZip('1.2.0')),
            $this->multipartServer(),
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_INSTALLED, $outcome['status']);
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $outcome['result']);
        $this->assertFalse($outcome['result']->activated());
        $this->assertIsArray($outcome['redirect_query']);
        $this->assertArrayNotHasKey('activated', $outcome['redirect_query']);
        $this->assertSame(
            'Plugin installed: demo-notes/demo-notes.php. You can activate it below.',
            AP_Admin_Plugin_Upload::successNotice($outcome['redirect_query'])
        );
        $this->assertFalse(AP_Plugin::isActive('demo-notes/demo-notes.php', $this->db));
    }

    public function testActivationFailureNoticeLeavesThePluginInactive(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('failact', 'administrator');
        $this->useInactivePluginDirectory();

        $outcome = $this->submit(
            'POST',
            $this->uploadPost($adminId, [AP_Admin_Plugin_Upload::ACTIVATE_FIELD => '1']),
            $this->fileBag($this->pluginZip('1.2.0')),
            $this->multipartServer(),
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_REJECTED, $outcome['status']);
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $outcome['error']);
        $this->assertSame(AP_Plugin_Install_Error::ACTIVATION_FAILED, $outcome['error']->code());
        $this->assertSame('error', $outcome['notice_type']);
        $this->assertSame($outcome['error']->message(), $outcome['notice']);
        $this->assertSame(
            AP_Admin_Plugin_Upload::noticeForError($outcome['error']),
            $outcome['notice']
        );
        $this->assertStringContainsString('remains inactive', $outcome['notice']);
        $this->assertNull($outcome['redirect_query']);
        $this->assertFileExists($this->pluginsRoot . '/demo-notes/demo-notes.php');
        $this->assertFalse(AP_Plugin::isActive('demo-notes/demo-notes.php', $this->db));
    }

    public function testConfirmCheckboxActivatesTheReplacement(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('confact', 'administrator');
        $this->installDemo('1.0.0');
        $held = $this->holdUpgrade($adminId, '2.0.0');
        $this->assertFalse($held['confirmation']['activate']);
        $token = $held['confirmation']['token'];
        $html = AP_Admin_Plugin_Upload::render($adminId, $this->db, $held['confirmation']);
        $this->assertDoesNotMatchRegularExpression(
            '/id="ap-plugin-overwrite-activate"[^>]*checked/',
            $html
        );

        $this->useInactivePluginDirectory();
        $outcome = $this->submit(
            'POST',
            $this->confirmPost($adminId, $token, AP_Admin_Plugin_Upload::POST_CONFIRM, [
                AP_Admin_Plugin_Upload::ACTIVATE_FIELD => '1',
            ]),
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_REJECTED, $outcome['status']);
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $outcome['error']);
        $this->assertSame(AP_Plugin_Install_Error::ACTIVATION_FAILED, $outcome['error']->code());
        $this->assertStringContainsString('remains inactive', $outcome['notice']);
        $this->assertStringContainsString('upgraded', $outcome['notice']);
        $this->assertPluginVersion('2.0.0');
        $this->assertDirectoryDoesNotExist($this->pendingDir($token));
    }

    public function testConfirmActivatesWhenThePluginsDirectoryIsLive(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('liveconf', 'administrator');
        $this->useLivePluginDirectory();
        $this->installDemo('1.0.0');
        $held = $this->holdUpgrade($adminId, '2.0.0');
        $token = $held['confirmation']['token'];

        $outcome = $this->submit(
            'POST',
            $this->confirmPost($adminId, $token, AP_Admin_Plugin_Upload::POST_CONFIRM, [
                AP_Admin_Plugin_Upload::ACTIVATE_FIELD => '1',
            ]),
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_REPLACED, $outcome['status']);
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $outcome['result']);
        $this->assertTrue($outcome['result']->activated());
        $this->assertIsArray($outcome['redirect_query']);
        $this->assertSame('1', $outcome['redirect_query']['activated']);
        $this->assertSame(
            'Demo Notes updated from version 1.0.0 to version 2.0.0 and activated.',
            AP_Admin_Plugin_Upload::successNotice($outcome['redirect_query'])
        );
        $this->assertPluginVersion('2.0.0');
        $this->assertTrue(AP_Plugin::isActive('demo-notes/demo-notes.php', $this->db));
    }

    public function testStoredActivateChoiceDoesNotActivateUnlessConfirmPostsIt(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('storedact', 'administrator');
        $this->installDemo('1.0.0');
        $held = $this->submit(
            'POST',
            $this->uploadPost($adminId, [AP_Admin_Plugin_Upload::ACTIVATE_FIELD => '1']),
            $this->fileBag($this->pluginZip('2.0.0')),
            $this->multipartServer(),
            $adminId,
            true
        );
        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_NEEDS_CONFIRMATION, $held['status']);
        $this->assertTrue($held['confirmation']['activate']);
        $token = $held['confirmation']['token'];

        $outcome = $this->submit(
            'POST',
            $this->confirmPost($adminId, $token, AP_Admin_Plugin_Upload::POST_CONFIRM),
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_REPLACED, $outcome['status']);
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $outcome['result']);
        $this->assertFalse($outcome['result']->activated());
        $this->assertIsArray($outcome['redirect_query']);
        $this->assertArrayNotHasKey('activated', $outcome['redirect_query']);
        $this->assertPluginVersion('2.0.0');
    }

    public function testPendingActivateFlagMustBeBoolean(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $adminId = $this->createUser('badflag', 'administrator');
        $this->installDemo('1.0.0');
        $held = $this->holdUpgrade($adminId, '2.0.0');
        $token = $held['confirmation']['token'];
        $jsonPath = $this->pendingJson($token);
        $record = json_decode((string) file_get_contents($jsonPath), true);
        $this->assertIsArray($record);
        $this->assertFalse($record['activate']);
        $record['activate'] = '1';
        $this->assertNotFalse(file_put_contents(
            $jsonPath,
            json_encode($record, JSON_THROW_ON_ERROR)
        ));

        $outcome = $this->submit(
            'POST',
            $this->confirmPost($adminId, $token, AP_Admin_Plugin_Upload::POST_CONFIRM),
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $adminId,
            true
        );

        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_BAD_CONFIRMATION, $outcome['status']);
        $this->assertSame(AP_Admin_Plugin_Upload::NOTICE_CONFIRM_INVALID, $outcome['notice']);
        $this->assertPluginVersion('1.0.0');
        $this->assertDirectoryDoesNotExist($this->pendingDir($token));
    }

    public function testErrorNoticesCoverEveryInstallerCode(): void
    {
        $this->assertSame(
            AP_Plugin_Install_Error::CODES,
            array_keys(AP_Admin_Plugin_Upload::ERROR_NOTICES)
        );

        foreach (AP_Plugin_Install_Error::CODES as $code) {
            $error = AP_Plugin_Install_Error::make($code, 'Installer detail ' . $code);
            $notice = AP_Admin_Plugin_Upload::noticeForError($error);
            $this->assertNotSame('', $notice, $code);
            if ($code === AP_Plugin_Install_Error::ACTIVATION_FAILED) {
                $this->assertSame('Installer detail ' . $code, $notice);
                $this->assertNotSame('', AP_Admin_Plugin_Upload::ERROR_NOTICES[$code]);
                continue;
            }
            $this->assertSame(AP_Admin_Plugin_Upload::ERROR_NOTICES[$code], $notice, $code);
            $this->assertStringNotContainsString('Installer detail', $notice);
        }

        $unknown = AP_Plugin_Install_Error::make('not_a_real_code', 'secret path /tmp/plugin.zip');
        $this->assertSame(
            AP_Admin_Plugin_Upload::NOTICE_UNKNOWN_ERROR,
            AP_Admin_Plugin_Upload::noticeForError($unknown)
        );
        $this->assertStringNotContainsString('secret path', AP_Admin_Plugin_Upload::noticeForError($unknown));
    }

    public function testSuccessNoticesDistinguishActivation(): void
    {
        $this->assertSame(
            'Plugin installed: demo-notes/demo-notes.php. You can activate it below.',
            AP_Admin_Plugin_Upload::successNotice([
                'message' => AP_Admin_Plugin_Upload::MESSAGE_INSTALLED,
                'plugin' => 'demo-notes/demo-notes.php',
            ])
        );
        $this->assertSame(
            'Plugin installed and activated: demo-notes/demo-notes.php.',
            AP_Admin_Plugin_Upload::successNotice([
                'message' => AP_Admin_Plugin_Upload::MESSAGE_INSTALLED,
                'plugin' => 'demo-notes/demo-notes.php',
                'activated' => '1',
            ])
        );
        $this->assertSame(
            'Demo Notes updated from version 1.0.0 to version 2.0.0.',
            AP_Admin_Plugin_Upload::successNotice([
                'message' => AP_Admin_Plugin_Upload::MESSAGE_REPLACED,
                'plugin' => 'demo-notes/demo-notes.php',
                'plugin_name' => 'Demo Notes',
                'plugin_from' => '1.0.0',
                'plugin_to' => '2.0.0',
            ])
        );
        $this->assertSame(
            'Demo Notes updated from version 1.0.0 to version 2.0.0 and activated.',
            AP_Admin_Plugin_Upload::successNotice([
                'message' => AP_Admin_Plugin_Upload::MESSAGE_REPLACED,
                'plugin' => 'demo-notes/demo-notes.php',
                'plugin_name' => 'Demo Notes',
                'plugin_from' => '1.0.0',
                'plugin_to' => '2.0.0',
                'activated' => '1',
            ])
        );
        $this->assertSame(
            'demo-notes/demo-notes.php updated from version 1.0.0 to version 2.0.0.',
            AP_Admin_Plugin_Upload::successNotice([
                'message' => 'plugin_replaced',
                'plugin' => 'demo-notes/demo-notes.php',
                'plugin_name' => "Bad\nName",
                'plugin_from' => '1.0.0',
                'plugin_to' => '2.0.0',
            ])
        );
        $this->assertSame(
            'Plugin replaced: demo-notes/demo-notes.php.',
            AP_Admin_Plugin_Upload::successNotice([
                'message' => 'plugin_replaced',
                'plugin' => 'demo-notes/demo-notes.php',
                'plugin_from' => "1\n0",
                'plugin_to' => '2.0.0',
            ])
        );
        $this->assertSame(
            'Plugin replaced and activated: demo-notes/demo-notes.php.',
            AP_Admin_Plugin_Upload::successNotice([
                'message' => 'plugin_replaced',
                'plugin' => 'demo-notes/demo-notes.php',
                'activated' => '1',
            ])
        );
        $this->assertSame(
            'Plugin installed. You can activate it below.',
            AP_Admin_Plugin_Upload::successNotice([
                'message' => 'plugin_installed',
                'plugin' => ['demo-notes/demo-notes.php'],
            ])
        );
    }

    public function testPluginsScreenDelegatesUploadToTheFormHandler(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/plugins.php');
        $this->assertStringContainsString("requireCapability('activate_plugins')", $src);
        $this->assertStringContainsString('AP_Admin_Plugin_Upload::handle', $src);
        $this->assertStringContainsString('AP_Admin_Plugin_Upload::render', $src);
        $this->assertStringContainsString('STATUS_NEEDS_CONFIRMATION', $src);
        $this->assertStringContainsString('AP_Admin_Plugin_Upload::successNotice', $src);
        $this->assertStringContainsString('MESSAGE_INSTALLED', $src);
        $this->assertStringContainsString('MESSAGE_REPLACED', $src);
        $this->assertStringNotContainsString('handleUpload', $src);
        $this->assertStringNotContainsString('installFromZip', $src);

        $form = (string) file_get_contents(
            $this->root . '/ap-admin/includes/class-ap-admin-plugin-upload.php'
        );
        $this->assertStringContainsString('Activate after install', $form);
        $this->assertStringContainsString('function noticeForError', $form);
        $this->assertStringContainsString('function successNotice', $form);
        $this->assertStringContainsString('plugin_from', $form);
        $this->assertStringContainsString('plugin_to', $form);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function uploadPost(int $userId, array $extra = []): array
    {
        return [
            'ap_plugin_action' => 'upload',
            '_ap_nonce' => ap_create_nonce(AP_Admin_Plugin_Upload::NONCE_ACTION, $userId),
        ] + $extra;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fileBag(string $path): array
    {
        $size = filesize($path);

        return [
            AP_Admin_Plugin_Upload::FILE_FIELD => [
                'name' => 'demo-notes.zip',
                'type' => 'application/zip',
                'tmp_name' => $path,
                'error' => UPLOAD_ERR_OK,
                'size' => is_int($size) ? $size : 0,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function multipartServer(): array
    {
        return ['CONTENT_TYPE' => 'multipart/form-data'];
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     *
     * @return array{
     *   handled: bool,
     *   status: string,
     *   notice: string,
     *   notice_type: string,
     *   redirect_query: array<string, string>|null,
     *   result: AP_Plugin_Install_Result|null,
     *   error: AP_Plugin_Install_Error|null,
     *   confirmation: array{
     *     token: string,
     *     slug: string,
     *     name: string,
     *     version: string,
     *     current_name: string,
     *     current_version: string,
     *     plugin: string
     *   }|null
     * }
     */
    private function submit(
        string $method,
        array $post,
        array $files,
        array $server,
        int $userId,
        bool $testMode
    ): array {
        $opts = [
            'plugins_root' => $this->pluginsRoot,
            'content_dir' => $this->contentDir,
        ];
        if ($testMode) {
            $opts['test_mode'] = true;
        }

        return AP_Admin_Plugin_Upload::handle(
            $method,
            $post,
            $files,
            $server,
            $userId,
            $this->db,
            $opts
        );
    }

    private function createUser(string $login, string $role): int
    {
        $ok = $this->db->insert('users', [
            'user_login' => $login,
            'user_pass' => AP_User::hashPassword('pass-' . $login),
            'user_nicename' => $login,
            'user_email' => $login . '@example.test',
            'user_url' => '',
            'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => $login,
        ]);
        $this->assertNotFalse($ok);
        $id = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $id);
        $this->assertTrue(AP_Roles::setUserRole($id, $role, $this->db));

        return $id;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function confirmPost(int $userId, string $token, string $action, array $extra = []): array
    {
        return [
            'ap_plugin_action' => $action,
            '_ap_nonce' => ap_create_nonce(AP_Admin_Plugin_Upload::NONCE_OVERWRITE, $userId),
            AP_Admin_Plugin_Upload::TOKEN_FIELD => $token,
        ] + $extra;
    }

    /**
     * Point plugin activation at this test's plugins directory and database.
     */
    private function useLivePluginDirectory(): void
    {
        $this->useInactivePluginDirectory();
        AP_Plugin::setPluginsRootOverride($this->pluginsRoot);
    }

    /**
     * Load the plugin API against this test database without treating the
     * temporary plugins directory as the live one. Activation then fails and
     * the notice says the plugin remains inactive.
     */
    private function useInactivePluginDirectory(): void
    {
        require_once $this->root . '/ap-includes/class-ap-plugin.php';
        AP_Plugin::reset();
        AP_Options::flushCache();
        $GLOBALS['apdb'] = $this->db;
        AP_Options::update('active_plugins', [], $this->db);
    }

    /**
     * @return array{
     *   handled: bool,
     *   status: string,
     *   notice: string,
     *   notice_type: string,
     *   redirect_query: array<string, string>|null,
     *   result: AP_Plugin_Install_Result|null,
     *   error: AP_Plugin_Install_Error|null,
     *   confirmation: array{
     *     token: string,
     *     slug: string,
     *     name: string,
     *     version: string,
     *     current_name: string,
     *     current_version: string,
     *     plugin: string
     *   }
     * }
     */
    private function holdUpgrade(int $userId, string $version): array
    {
        $outcome = $this->submit(
            'POST',
            $this->uploadPost($userId),
            $this->fileBag($this->pluginZip($version)),
            $this->multipartServer(),
            $userId,
            true
        );
        $this->assertSame(AP_Admin_Plugin_Upload::STATUS_NEEDS_CONFIRMATION, $outcome['status']);
        $this->assertIsArray($outcome['confirmation']);
        $this->assertSame($version, $outcome['confirmation']['version']);
        $this->assertSame('1.0.0', $outcome['confirmation']['current_version']);

        return $outcome;
    }

    private function installDemo(string $version): void
    {
        $first = AP_Plugin_Installer::install($this->pluginZip($version), [
            'plugins_root' => $this->pluginsRoot,
            'content_dir' => $this->contentDir,
        ]);
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $first);
    }

    private function pendingDir(string $token): string
    {
        return $this->contentDir . '/' . AP_Admin_Plugin_Upload::PENDING_DIR . '/' . $token;
    }

    private function pendingJson(string $token): string
    {
        return $this->pendingDir($token) . '/pending.json';
    }

    private function assertPendingHeld(string $token): void
    {
        $root = $this->contentDir . '/' . AP_Admin_Plugin_Upload::PENDING_DIR;
        $this->assertDirectoryExists($root);
        $this->assertFileExists($root . '/.htaccess');
        $this->assertStringContainsString('Require all denied', (string) file_get_contents($root . '/.htaccess'));
        $this->assertFileExists($this->pendingDir($token) . '/package.zip');
        $this->assertFileExists($this->pendingJson($token));
        $pendingReal = realpath($root);
        $pluginsReal = realpath($this->pluginsRoot);
        $this->assertIsString($pendingReal);
        $this->assertIsString($pluginsReal);
        $this->assertFalse(str_starts_with($pendingReal, $pluginsReal . DIRECTORY_SEPARATOR));
        $this->assertFalse(str_starts_with($pluginsReal, $pendingReal . DIRECTORY_SEPARATOR));
    }

    private function assertPluginVersion(string $version): void
    {
        $body = (string) file_get_contents($this->pluginsRoot . '/demo-notes/demo-notes.php');
        $this->assertStringContainsString('Version: ' . $version, $body);
        if ($version !== '1.0.0') {
            $this->assertStringNotContainsString('Version: 1.0.0', $body);
        }
        if ($version !== '2.0.0') {
            $this->assertStringNotContainsString('Version: 2.0.0', $body);
        }
    }

    private function pluginZip(string $version, string $name = 'Demo Notes'): string
    {
        $php = "<?php\n/**\n * Plugin Name: {$name}\n * Version: {$version}\n */\n";
        $path = $this->workDir . '/pkg-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $this->assertTrue($zip->addFromString('demo-notes/demo-notes.php', $php));
        $zip->close();

        return $path;
    }

    private function assertPluginAbsent(): void
    {
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/demo-notes');
        $this->assertFileDoesNotExist($this->pluginsRoot . '/demo-notes.php');
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
