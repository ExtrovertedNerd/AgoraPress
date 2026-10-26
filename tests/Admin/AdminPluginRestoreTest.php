<?php

/**
 * Plugins screen "Restore previous version": POST, nonce, capability, backup.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Admin_Plugin_Restore;
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

#[CoversClass(AP_Admin_Plugin_Restore::class)]
final class AdminPluginRestoreTest extends TestCase
{
    private const STAMP = '20240101000000000002-bbbbbbb2';

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
        require_once $this->root . '/ap-admin/includes/class-ap-admin-plugin-restore.php';

        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('r', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('s', 32));
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

        $this->workDir = sys_get_temp_dir() . '/ap-plugin-restore-form-' . uniqid('', true);
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

    public function testRestoreActionAppearsOnlyWhenABackupExists(): void
    {
        $adminId = $this->createUser('restoreadmin', 'administrator');
        $editorId = $this->createUser('restoreeditor', 'editor');
        $this->seedFolder('sample', '2.0.0');
        $this->seedFolder('other', '1.0.0');
        $this->seedBackup('sample', '1.0.0');

        $html = AP_Admin_Plugin_Restore::renderAction($adminId, 'sample/sample.php', $this->db, $this->opts());

        $this->assertStringContainsString(AP_Admin_Plugin_Restore::LABEL, $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('class="ap-plugin-restore-form"', $html);
        $this->assertStringNotContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="ap_plugin_action"', $html);
        $this->assertStringContainsString('value="restore"', $html);
        $this->assertStringContainsString('name="plugin_slug"', $html);
        $this->assertStringContainsString('value="sample"', $html);
        $this->assertStringContainsString('name="_ap_nonce"', $html);
        $this->assertStringContainsString('Restore version 1.0.0', $html);
        $this->assertStringContainsString('Sample', $html);
        $this->assertSame(1, preg_match('/name="_ap_nonce" value="([^"]+)"/', $html, $match));
        $nonce = (string) $match[1];
        $this->assertTrue(ap_check_nonce($nonce, AP_Admin_Plugin_Restore::nonceAction('sample'), $adminId));
        $this->assertFalse(ap_check_nonce($nonce, AP_Admin_Plugin_Restore::nonceAction('other'), $adminId));
        $this->assertFalse(ap_check_nonce($nonce, 'plugin-upload', $adminId));

        $this->assertSame(
            '',
            AP_Admin_Plugin_Restore::renderAction($adminId, 'other/other.php', $this->db, $this->opts())
        );
        $this->assertSame(
            '',
            AP_Admin_Plugin_Restore::renderAction($adminId, 'Demo/Demo.php', $this->db, $this->opts())
        );
        $this->assertSame(
            '',
            AP_Admin_Plugin_Restore::renderAction($editorId, 'sample/sample.php', $this->db, $this->opts())
        );
        $this->assertSame(
            '',
            AP_Admin_Plugin_Restore::renderAction(0, 'sample/sample.php', $this->db, $this->opts())
        );
        $this->assertSame('2.0.0', $this->liveVersion('sample'));
        $this->assertFileExists($this->backupFile('sample'));
    }

    public function testGetAndUnrelatedPostDoNotRestore(): void
    {
        $adminId = $this->createUser('getrestore', 'administrator');
        $this->seedFolder('sample', '2.0.0');
        $this->seedBackup('sample', '1.0.0');

        $get = $this->submit('GET', $this->restorePost($adminId), $adminId);
        $this->assertFalse($get['handled']);
        $this->assertSame(AP_Admin_Plugin_Restore::STATUS_IGNORED, $get['status']);
        $this->assertNull($get['result']);

        $upload = $this->submit('POST', [
            'ap_plugin_action' => 'upload',
            '_ap_nonce' => ap_create_nonce(AP_Admin_Plugin_Restore::nonceAction('sample'), $adminId),
            'plugin_slug' => 'sample',
        ], $adminId);
        $this->assertFalse($upload['handled']);
        $this->assertSame(AP_Admin_Plugin_Restore::STATUS_IGNORED, $upload['status']);
        $this->assertSame('2.0.0', $this->liveVersion('sample'));
        $this->assertFileExists($this->backupFile('sample'));
    }

    public function testMissingNonceIsRefused(): void
    {
        $adminId = $this->createUser('noncerestore', 'administrator');
        $this->seedFolder('sample', '2.0.0');
        $this->seedBackup('sample', '1.0.0');

        $post = $this->restorePost($adminId);
        unset($post['_ap_nonce']);
        $missing = $this->submit('POST', $post, $adminId);
        $this->assertTrue($missing['handled']);
        $this->assertSame(AP_Admin_Plugin_Restore::STATUS_BAD_NONCE, $missing['status']);
        $this->assertSame(AP_Admin_Plugin_Restore::NOTICE_BAD_NONCE, $missing['notice']);
        $this->assertSame('error', $missing['notice_type']);
        $this->assertNull($missing['result']);
        $this->assertNull($missing['error']);

        $post['_ap_nonce'] = ap_create_nonce(AP_Admin_Plugin_Restore::nonceAction('other'), $adminId);
        $wrongSlug = $this->submit('POST', $post, $adminId);
        $this->assertSame(AP_Admin_Plugin_Restore::STATUS_BAD_NONCE, $wrongSlug['status']);

        $post['_ap_nonce'] = ap_create_nonce('plugin-upload', $adminId);
        $wrongAction = $this->submit('POST', $post, $adminId);
        $this->assertSame(AP_Admin_Plugin_Restore::STATUS_BAD_NONCE, $wrongAction['status']);

        $this->assertSame('2.0.0', $this->liveVersion('sample'));
        $this->assertFileExists($this->backupFile('sample'));
    }

    public function testNonAdminIsRefusedBeforeNonce(): void
    {
        $editorId = $this->createUser('caprestore', 'editor');
        $this->seedFolder('sample', '2.0.0');
        $this->seedBackup('sample', '1.0.0');

        $noNonce = $this->submit('POST', [
            'ap_plugin_action' => 'restore',
            'plugin_slug' => 'sample',
        ], $editorId);
        $this->assertTrue($noNonce['handled']);
        $this->assertSame(AP_Admin_Plugin_Restore::STATUS_FORBIDDEN, $noNonce['status']);
        $this->assertSame(AP_Admin_Plugin_Restore::NOTICE_FORBIDDEN, $noNonce['notice']);
        $this->assertNull($noNonce['result']);
        $this->assertNull($noNonce['error']);

        $withNonce = $this->submit('POST', $this->restorePost($editorId), $editorId);
        $this->assertSame(AP_Admin_Plugin_Restore::STATUS_FORBIDDEN, $withNonce['status']);
        $this->assertFalse(AP_Roles::userCan($editorId, 'install_plugins', null, $this->db));
        $this->assertSame('2.0.0', $this->liveVersion('sample'));
        $this->assertFileExists($this->backupFile('sample'));
    }

    public function testRestoreWithoutABackupIsRefused(): void
    {
        $adminId = $this->createUser('emptyrestore', 'administrator');
        $this->seedFolder('sample', '2.0.0');

        $outcome = $this->submit('POST', $this->restorePost($adminId), $adminId);

        $this->assertTrue($outcome['handled']);
        $this->assertSame(AP_Admin_Plugin_Restore::STATUS_REJECTED, $outcome['status']);
        $this->assertSame(
            AP_Admin_Plugin_Upload::ERROR_NOTICES[AP_Plugin_Install_Error::NO_BACKUP],
            $outcome['notice']
        );
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $outcome['error']);
        $this->assertSame(AP_Plugin_Install_Error::NO_BACKUP, $outcome['error']->code());
        $this->assertNull($outcome['redirect_query']);
        $this->assertSame('2.0.0', $this->liveVersion('sample'));
        $this->assertDirectoryDoesNotExist($this->contentDir . '/.ap-plugin-backups');
    }

    public function testAdminRestoreUsesTheFormNonceAndSwapsTheBackup(): void
    {
        $adminId = $this->createUser('happyrestore', 'administrator');
        $this->seedFolder('sample', '2.0.0');
        $this->seedBackup('sample', '1.0.0');
        $html = AP_Admin_Plugin_Restore::renderAction($adminId, 'sample/sample.php', $this->db, $this->opts());
        $this->assertSame(1, preg_match('/name="_ap_nonce" value="([^"]+)"/', $html, $match));

        $outcome = $this->submit('POST', [
            'ap_plugin_action' => 'restore',
            'plugin_slug' => 'sample',
            '_ap_nonce' => (string) $match[1],
        ], $adminId);

        $this->assertTrue($outcome['handled']);
        $this->assertSame(AP_Admin_Plugin_Restore::STATUS_RESTORED, $outcome['status']);
        $this->assertSame('', $outcome['notice']);
        $this->assertSame('success', $outcome['notice_type']);
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $outcome['result']);
        $this->assertSame(AP_Plugin_Install_Result::ACTION_RESTORED, $outcome['result']->action());
        $this->assertSame('1.0.0', $outcome['result']->version());
        $this->assertSame('2.0.0', $outcome['result']->previousVersion());
        $this->assertSame('sample/sample.php', $outcome['result']->plugin());
        $this->assertNull($outcome['error']);
        $this->assertIsArray($outcome['redirect_query']);
        $this->assertSame(AP_Admin_Plugin_Restore::MESSAGE_RESTORED, $outcome['redirect_query']['message']);
        $this->assertSame('sample/sample.php', $outcome['redirect_query']['plugin']);
        $this->assertSame('Sample', $outcome['redirect_query']['plugin_name']);
        $this->assertSame('2.0.0', $outcome['redirect_query']['plugin_from']);
        $this->assertSame('1.0.0', $outcome['redirect_query']['plugin_to']);
        $this->assertArrayNotHasKey('activated', $outcome['redirect_query']);
        $this->assertSame(
            'Sample restored from version 2.0.0 to version 1.0.0.',
            AP_Admin_Plugin_Restore::successNotice($outcome['redirect_query'])
        );
        $this->assertSame('1.0.0', $this->liveVersion('sample'));
        $this->assertFileDoesNotExist($this->backupFile('sample'));

        $again = AP_Admin_Plugin_Restore::renderAction($adminId, 'sample/sample.php', $this->db, $this->opts());
        $this->assertStringContainsString(AP_Admin_Plugin_Restore::LABEL, $again);
        $this->assertStringContainsString('Restore version 2.0.0', $again);
    }

    public function testSuccessNoticeSkipsUnsafeQueryValues(): void
    {
        $this->assertSame(
            'Sample restored from version 2.0.0 to version 1.0.0 and is still active.',
            AP_Admin_Plugin_Restore::successNotice([
                'message' => AP_Admin_Plugin_Restore::MESSAGE_RESTORED,
                'plugin' => 'sample/sample.php',
                'plugin_name' => 'Sample',
                'plugin_from' => '2.0.0',
                'plugin_to' => '1.0.0',
                'activated' => '1',
            ])
        );
        $this->assertSame(
            'sample/sample.php restored to version 1.0.0.',
            AP_Admin_Plugin_Restore::successNotice([
                'message' => 'plugin_restored',
                'plugin' => 'sample/sample.php',
                'plugin_name' => "Bad\nName",
                'plugin_to' => '1.0.0',
            ])
        );
        $this->assertSame(
            'Plugin restored: sample/sample.php.',
            AP_Admin_Plugin_Restore::successNotice([
                'plugin' => 'sample/sample.php',
                'plugin_from' => "2\n0",
                'plugin_to' => "1\n0",
            ])
        );
    }

    public function testPluginsScreenWiresTheRestoreAction(): void
    {
        $screen = (string) file_get_contents($this->root . '/ap-admin/plugins.php');
        $this->assertStringContainsString('class-ap-admin-plugin-restore.php', $screen);
        $this->assertStringContainsString('AP_Admin_Plugin_Restore::handle', $screen);
        $this->assertStringContainsString('AP_Admin_Plugin_Restore::renderAction', $screen);
        $this->assertStringContainsString('AP_Admin_Plugin_Restore::MESSAGE_RESTORED', $screen);
        $this->assertStringContainsString('AP_Admin_Plugin_Restore::successNotice', $screen);
        $this->assertStringContainsString('Restore previous version', $screen);

        $class = (string) file_get_contents(
            $this->root . '/ap-admin/includes/class-ap-admin-plugin-restore.php'
        );
        $this->assertStringContainsString('AP_Plugin_Installer::restorePrevious', $class);
        $this->assertStringContainsString('AP_Plugin_Installer::latestBackup', $class);
        $this->assertStringContainsString('install_plugins', $class);
        $this->assertStringContainsString('restore-plugin_', $class);
        $this->assertStringContainsString('method="post"', $class);
        $this->assertStringContainsString(AP_Admin_Plugin_Restore::LABEL, $class);
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return array{
     *   handled: bool,
     *   status: string,
     *   notice: string,
     *   notice_type: string,
     *   redirect_query: array<string, string>|null,
     *   result: AP_Plugin_Install_Result|null,
     *   error: AP_Plugin_Install_Error|null
     * }
     */
    private function submit(string $method, array $post, int $userId): array
    {
        return AP_Admin_Plugin_Restore::handle($method, $post, $userId, $this->db, $this->opts());
    }

    /**
     * @return array<string, string>
     */
    private function restorePost(int $userId): array
    {
        return [
            'ap_plugin_action' => 'restore',
            'plugin_slug' => 'sample',
            '_ap_nonce' => ap_create_nonce(AP_Admin_Plugin_Restore::nonceAction('sample'), $userId),
        ];
    }

    /**
     * @return array{plugins_root: string, content_dir: string}
     */
    private function opts(): array
    {
        return [
            'plugins_root' => $this->pluginsRoot,
            'content_dir' => $this->contentDir,
        ];
    }

    private function seedFolder(string $slug, string $version): void
    {
        $dir = $this->pluginsRoot . '/' . $slug;
        $this->assertTrue(mkdir($dir, 0700, true));
        $this->assertNotFalse(file_put_contents($dir . '/' . $slug . '.php', $this->pluginPhp($version)));
    }

    private function seedBackup(string $slug, string $version): void
    {
        $dir = $this->contentDir . '/.ap-plugin-backups/' . $slug . '/' . self::STAMP . '/' . $slug;
        $this->assertTrue(mkdir($dir, 0700, true));
        $this->assertNotFalse(file_put_contents($dir . '/' . $slug . '.php', $this->pluginPhp($version)));
    }

    private function pluginPhp(string $version): string
    {
        return "<?php\n/**\n * Plugin Name: Sample\n * Version: {$version}\n */\n";
    }

    private function liveVersion(string $slug): string
    {
        $raw = (string) file_get_contents($this->pluginsRoot . '/' . $slug . '/' . $slug . '.php');
        $this->assertSame(1, preg_match('/Version: ([0-9.]+)/', $raw, $match));

        return (string) $match[1];
    }

    private function backupFile(string $slug): string
    {
        return $this->contentDir . '/.ap-plugin-backups/' . $slug . '/' . self::STAMP . '/'
            . $slug . '/' . $slug . '.php';
    }

    private function createUser(string $login, string $role): int
    {
        $ok = $this->db->insert('users', [
            'user_login' => $login,
            'user_pass' => AP_User::hashPassword('pass-' . $login),
            'user_nicename' => $login,
            'user_email' => $login . '@example.com',
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

    private function removeDir(string $dir): void
    {
        if (is_link($dir)) {
            unlink($dir);

            return;
        }
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
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
                continue;
            }
            $this->removeDir($path);
        }
        rmdir($dir);
    }
}
