<?php

/**
 * Tests for Settings API (AP_Settings) and core settings helpers.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Options;

use AP_DB;
use AP_Media;
use AP_Migrator;
use AP_Options;
use AP_Rewrite;
use AP_Settings;
use PDO;
use PHPUnit\Framework\TestCase;

final class SettingsApiTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-settings.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key');
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt');
        }
        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key');
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt');
        }

        AP_Options::flushCache();
        AP_Settings::flush();
        AP_Rewrite::resetCache();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        $this->db->insert('options', [
            'option_name' => 'blogname',
            'option_value' => 'Test Site',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'ap_module_static_pages',
            'option_value' => '1',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'ap_module_blog',
            'option_value' => '1',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'ap_module_forum',
            'option_value' => '1',
            'autoload' => 'yes',
        ]);

        $GLOBALS['apdb'] = $this->db;
        AP_Settings::registerCore();
    }

    protected function tearDown(): void
    {
        AP_Options::flushCache();
        AP_Settings::flush();
        unset($GLOBALS['apdb']);
    }

    public function testRegisterSettingAndSave(): void
    {
        AP_Settings::flush();
        AP_Settings::registerSetting('demo', 'demo_opt', [
            'type' => 'string',
            'sanitize_callback' => static function (mixed $v): string {
                return strtoupper(trim((string) ($v ?? '')));
            },
        ]);

        $ok = AP_Settings::save('demo', ['demo_opt' => '  hello '], $this->db);
        $this->assertTrue($ok);
        $this->assertSame('HELLO', AP_Options::get('demo_opt', '', $this->db));
    }

    public function testSectionsAndFieldsRegistry(): void
    {
        AP_Settings::flush();
        AP_Settings::addSection('main', 'Main', null, 'demo-page');
        AP_Settings::addField(
            'title_field',
            'Title',
            static function (): void {
                echo '<input name="title">';
            },
            'demo-page',
            'main'
        );

        $sections = AP_Settings::getSections('demo-page');
        $this->assertArrayHasKey('main', $sections);
        $this->assertSame('Main', $sections['main']['title']);

        $fields = AP_Settings::getFields('demo-page', 'main');
        $this->assertArrayHasKey('title_field', $fields);
    }

    public function testDoSectionsRendersHtml(): void
    {
        AP_Settings::flush();
        AP_Settings::addSection('sec', 'Section Title', null, 'render-page');
        AP_Settings::addField(
            'f1',
            'Field One',
            static function (): void {
                echo '<input id="f1" name="f1" value="x">';
            },
            'render-page',
            'sec'
        );

        ob_start();
        AP_Settings::doSections('render-page');
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Section Title', $html);
        $this->assertStringContainsString('Field One', $html);
        $this->assertStringContainsString('name="f1"', $html);
    }

    public function testSettingsFieldsIncludesNonceAndOptionPage(): void
    {
        $html = AP_Settings::settingsFields('general', false);
        $this->assertStringContainsString('name="option_page"', $html);
        $this->assertStringContainsString('value="general"', $html);
        $this->assertStringContainsString('_ap_nonce', $html);
    }

    public function testCoreGroupsRegistered(): void
    {
        $groups = ['general', 'modules', 'writing', 'reading', 'discussion', 'media', 'permalink'];
        foreach ($groups as $group) {
            $regs = AP_Settings::getRegisteredSettings($group);
            $this->assertNotEmpty($regs, "Expected registered settings for group {$group}");
        }
        $this->assertArrayHasKey('blogname', AP_Settings::getRegisteredSettings('general'));
        $this->assertArrayHasKey('ap_module_blog', AP_Settings::getRegisteredSettings('modules'));
    }

    public function testUpdateGeneralSettings(): void
    {
        $ok = AP_Options::updateGeneralSettings([
            'blogname' => 'New Title',
            'blogdescription' => 'A tagline',
            'admin_email' => 'admin@example.test',
            'users_can_register' => '1',
            'require_email_verification' => '0',
            'registration_captcha' => 'math',
            'default_role' => 'author',
            'timezone_string' => 'Europe/Paris',
            'date_format' => 'F j, Y',
            'time_format' => 'g:i a',
            'start_of_week' => '0',
            'siteurl' => 'https://example.test',
            'home' => 'https://example.test',
        ], $this->db);

        $this->assertTrue($ok);
        $this->assertSame('New Title', AP_Options::get('blogname', '', $this->db));
        $this->assertSame('A tagline', AP_Options::get('blogdescription', '', $this->db));
        $this->assertSame('admin@example.test', AP_Options::get('admin_email', '', $this->db));
        $this->assertSame('1', (string) AP_Options::get('users_can_register', '0', $this->db));
        $this->assertSame('0', (string) AP_Options::get('require_email_verification', '1', $this->db));
        $this->assertSame('math', (string) AP_Options::get('registration_captcha', 'off', $this->db));
        $this->assertSame('author', AP_Options::get('default_role', '', $this->db));
        $this->assertSame('Europe/Paris', AP_Options::get('timezone_string', '', $this->db));
        $this->assertSame('0', (string) AP_Options::get('start_of_week', '1', $this->db));

        // Disable CAPTCHA again via settings save.
        $ok2 = AP_Options::updateGeneralSettings([
            'blogname' => 'New Title',
            'admin_email' => 'admin@example.test',
            'users_can_register' => '1',
            'require_email_verification' => '0',
            'registration_captcha' => 'off',
            'default_role' => 'author',
        ], $this->db);
        $this->assertTrue($ok2);
        $this->assertSame('off', (string) AP_Options::get('registration_captcha', 'math', $this->db));
    }

    public function testModulesAtLeastOneRequired(): void
    {
        $this->assertTrue(AP_Options::isModuleEnabled('blog', $this->db));

        $ok = AP_Options::updateModules([
            'static_pages' => '0',
            'blog' => '0',
            'forum' => '0',
        ], $this->db);
        $this->assertFalse($ok);
        // Unchanged.
        $this->assertTrue(AP_Options::isModuleEnabled('blog', $this->db));

        $ok = AP_Options::updateModules([
            'static_pages' => '0',
            'blog' => '1',
            'forum' => '0',
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertFalse(AP_Options::isModuleEnabled('static_pages', $this->db));
        $this->assertTrue(AP_Options::isModuleEnabled('blog', $this->db));
        $this->assertFalse(AP_Options::isModuleEnabled('forum', $this->db));
    }

    public function testUpdateDiscussionAndMediaSettings(): void
    {
        $ok = AP_Options::updateDiscussionSettings([
            'default_comment_status' => 'closed',
            'require_name_email' => '1',
            'comment_moderation' => '1',
            'show_avatars' => '0',
            'avatar_default' => 'identicon',
            'avatar_rating' => 'pg',
            'thread_comments_depth' => '99',
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame('closed', AP_Options::get('default_comment_status', '', $this->db));
        $this->assertSame('1', (string) AP_Options::get('comment_moderation', '0', $this->db));
        $this->assertSame('0', (string) AP_Options::get('show_avatars', '1', $this->db));
        $this->assertSame('identicon', AP_Options::get('avatar_default', '', $this->db));
        $this->assertSame('10', (string) AP_Options::get('thread_comments_depth', '5', $this->db));

        $ok = AP_Options::updateMediaSettings([
            'thumbnail_size_w' => 200,
            'thumbnail_size_h' => 200,
            'thumbnail_crop' => '1',
            'medium_size_w' => 400,
            'medium_size_h' => 400,
            'large_size_w' => 1200,
            'large_size_h' => 1200,
            'uploads_use_yearmonth_folders' => '0',
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame('200', (string) AP_Options::get('thumbnail_size_w', '0', $this->db));
        $this->assertSame('0', (string) AP_Options::get('uploads_use_yearmonth_folders', '1', $this->db));

        require_once $this->root . '/ap-includes/class-ap-media.php';
        $this->assertFalse(AP_Media::useYearMonthFolders($this->db));
    }

    public function testUpdatePermalinkSettings(): void
    {
        $ok = AP_Options::updatePermalinkSettings([
            'permalink_structure' => '/%postname%/',
            'category_base' => 'topics',
            'tag_base' => 'labels',
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame('/%postname%/', AP_Rewrite::getStructure($this->db));
        $this->assertSame('topics', AP_Rewrite::getCategoryBase($this->db));
        $this->assertSame('labels', AP_Rewrite::getTagBase($this->db));
    }

    public function testSanitizeCheckboxAndUrl(): void
    {
        $this->assertSame('1', AP_Settings::sanitizeCheckbox('on'));
        $this->assertSame('1', AP_Settings::sanitizeCheckbox('1'));
        $this->assertSame('0', AP_Settings::sanitizeCheckbox(null));
        $this->assertSame('0', AP_Settings::sanitizeCheckbox('0'));

        $this->assertSame('https://example.com', AP_Settings::sanitizeUrlOption('https://example.com/'));
        $this->assertSame('', AP_Settings::sanitizeUrlOption('javascript:alert(1)'));
        $this->assertSame('', AP_Settings::sanitizeUrlOption('not-a-url'));
    }

    public function testProceduralWrappersExist(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-includes/functions.php');
        foreach (
            [
                'function ap_register_setting',
                'function ap_add_settings_section',
                'function ap_add_settings_field',
                'function ap_settings_fields',
                'function ap_do_settings_sections',
                'function ap_is_module_enabled',
            ] as $needle
        ) {
            $this->assertStringContainsString($needle, $src);
        }
        $this->assertTrue(function_exists('ap_register_setting'));
        $this->assertTrue(function_exists('ap_is_module_enabled'));
        $this->assertTrue(ap_is_module_enabled('blog', $this->db));
    }

    public function testAdminScreensExistAndGateManageOptions(): void
    {
        $screens = [
            'options-general.php',
            'options-modules.php',
            'options-writing.php',
            'options-reading.php',
            'options-discussion.php',
            'options-media.php',
            'options-permalink.php',
        ];
        foreach ($screens as $file) {
            $path = $this->root . '/ap-admin/' . $file;
            $this->assertFileExists($path);
            $src = (string) file_get_contents($path);
            $this->assertStringContainsString('manage_options', $src, $file);
            $this->assertStringContainsString('requireCapability', $src, $file);
        }
    }

    public function testInstallerSeedsDiscussionAndMediaOptions(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-includes/class-ap-installer.php');
        foreach (
            [
                'default_comment_status',
                'require_name_email',
                'thumbnail_size_w',
                'uploads_use_yearmonth_folders',
                'use_smilies',
            ] as $opt
        ) {
            $this->assertStringContainsString("'" . $opt . "'", $src);
        }
    }

    public function testBootstrapLoadsSettingsApi(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-includes/bootstrap.php');
        $this->assertStringContainsString('class-ap-settings.php', $src);
        $this->assertStringContainsString('registerCore', $src);
    }
}
