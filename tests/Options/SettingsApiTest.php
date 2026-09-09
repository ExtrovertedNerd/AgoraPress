<?php

/**
 * Tests for Settings API (AP_Settings) and core settings helpers.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Options;

use AP_DB;
use AP_Mail;
use AP_Media;
use AP_Rate_Limit;
use AP_Migrator;
use AP_Options;
use AP_Registration;
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
        require_once $this->root . '/ap-includes/class-ap-mail.php';
        require_once $this->root . '/ap-includes/class-ap-smtp.php';
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
        AP_Mail::resetForTests();
        if (class_exists('AP_Rate_Limit', false)) {
            AP_Rate_Limit::disable();
        }

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
        if (class_exists('AP_Mail', false)) {
            AP_Mail::resetForTests();
        }
        if (class_exists('AP_Rate_Limit', false)) {
            AP_Rate_Limit::enable();
        }
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
        $groups = ['general', 'modules', 'writing', 'reading', 'discussion', 'media', 'permalink', 'mail'];
        foreach ($groups as $group) {
            $regs = AP_Settings::getRegisteredSettings($group);
            $this->assertNotEmpty($regs, "Expected registered settings for group {$group}");
        }
        $this->assertArrayHasKey('blogname', AP_Settings::getRegisteredSettings('general'));
        $this->assertArrayHasKey('site_icon', AP_Settings::getRegisteredSettings('general'));
        $this->assertArrayHasKey('reserved_usernames', AP_Settings::getRegisteredSettings('general'));
        $this->assertSame(
            [AP_Settings::class, 'sanitizeReservedUsernames'],
            AP_Settings::getRegisteredSettings('general')['reserved_usernames']['sanitize_callback']
        );
        $this->assertArrayHasKey('ap_module_blog', AP_Settings::getRegisteredSettings('modules'));
        $mail = AP_Settings::getRegisteredSettings('mail');
        $this->assertArrayHasKey('mail_from_name', $mail);
        $this->assertArrayHasKey('mail_from_email', $mail);
        $this->assertArrayHasKey('mail_reply_to', $mail);
        $this->assertArrayHasKey('mail_transport', $mail);
        $this->assertArrayHasKey('smtp_host', $mail);
        $this->assertArrayHasKey('smtp_port', $mail);
        $this->assertArrayHasKey('smtp_encryption', $mail);
        $this->assertArrayHasKey('smtp_user', $mail);
        $this->assertArrayHasKey('smtp_pass', $mail);
        $this->assertSame('no', $mail['smtp_pass']['autoload']);
        $this->assertArrayNotHasKey('admin_email', $mail);
        $this->assertArrayNotHasKey('mail_from_email', AP_Settings::getRegisteredSettings('general'));
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
            'site_icon' => 42,
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
        $this->assertSame(42, AP_Options::siteIcon($this->db));
        $this->assertSame('42', (string) AP_Options::get('site_icon', '0', $this->db));

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
        // site_icon omitted from input → preserved (not wiped to 0).
        $this->assertSame(42, AP_Options::siteIcon($this->db));
    }

    public function testRegistrationCaptchaSanitizerAcceptsGuard(): void
    {
        $this->assertSame(
            [AP_Settings::class, 'sanitizeRegistrationCaptcha'],
            AP_Settings::getRegisteredSettings('general')['registration_captcha']['sanitize_callback']
        );

        $this->assertSame('off', AP_Settings::sanitizeRegistrationCaptcha('off'));
        $this->assertSame('math', AP_Settings::sanitizeRegistrationCaptcha('math'));
        $this->assertSame('guard', AP_Settings::sanitizeRegistrationCaptcha('GUARD'));
        $this->assertSame('math', AP_Settings::sanitizeRegistrationCaptcha('1'));
        $this->assertSame('math', AP_Settings::sanitizeRegistrationCaptcha('yes'));
        $this->assertSame('off', AP_Settings::sanitizeRegistrationCaptcha('0'));
        $this->assertSame('off', AP_Settings::sanitizeRegistrationCaptcha('recaptcha'));
        $this->assertSame('off', AP_Settings::sanitizeRegistrationCaptcha('hcaptcha'));
        $this->assertSame('off', AP_Settings::sanitizeRegistrationCaptcha('turnstile'));
        $this->assertSame('off', AP_Settings::sanitizeRegistrationCaptcha('custombot'));

        $ok = AP_Options::updateGeneralSettings([
            'blogname' => 'Guard Site',
            'admin_email' => 'admin@example.test',
            'users_can_register' => '1',
            'registration_captcha' => 'guard',
            'default_role' => 'author',
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame('guard', (string) AP_Options::get('registration_captcha', 'off', $this->db));

        $viaSave = AP_Settings::save('general', [
            'blogname' => 'Guard Site',
            'blogdescription' => '',
            'siteurl' => 'https://example.test',
            'home' => 'https://example.test',
            'admin_email' => 'admin@example.test',
            'users_can_register' => '1',
            'require_email_verification' => '1',
            'registration_captcha' => 'Guard',
            'default_role' => 'author',
            'timezone_string' => 'UTC',
            'WPLANG' => '',
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i',
            'start_of_week' => '1',
            'site_icon' => 0,
        ], $this->db);
        $this->assertTrue($viaSave);
        $this->assertSame('guard', (string) AP_Options::get('registration_captcha', 'off', $this->db));

        $unknown = AP_Options::updateGeneralSettings([
            'blogname' => 'Guard Site',
            'admin_email' => 'admin@example.test',
            'users_can_register' => '1',
            'registration_captcha' => 'recaptcha',
            'default_role' => 'author',
        ], $this->db);
        $this->assertTrue($unknown);
        $this->assertSame('off', (string) AP_Options::get('registration_captcha', 'guard', $this->db));
    }

    public function testSiteIconOptionSaveAndClear(): void
    {
        $this->assertSame(0, AP_Options::siteIcon($this->db));

        $ok = AP_Options::updateGeneralSettings([
            'blogname' => 'Icon Site',
            'admin_email' => 'admin@example.test',
            'site_icon' => '99',
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame(99, AP_Options::siteIcon($this->db));

        // Negative / non-numeric coerced to 0 via max(0, (int)).
        $ok = AP_Options::updateGeneralSettings([
            'blogname' => 'Icon Site',
            'admin_email' => 'admin@example.test',
            'site_icon' => -5,
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame(0, AP_Options::siteIcon($this->db));

        $ok = AP_Options::updateGeneralSettings([
            'blogname' => 'Icon Site',
            'admin_email' => 'admin@example.test',
            'site_icon' => 7,
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame(7, AP_Options::siteIcon($this->db));

        // Explicit clear.
        $ok = AP_Options::updateGeneralSettings([
            'blogname' => 'Icon Site',
            'admin_email' => 'admin@example.test',
            'site_icon' => 0,
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame(0, AP_Options::siteIcon($this->db));
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
            'max_image_display_width' => 900,
            'uploads_use_yearmonth_folders' => '0',
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame('200', (string) AP_Options::get('thumbnail_size_w', '0', $this->db));
        $this->assertSame('900', (string) AP_Options::get('max_image_display_width', '0', $this->db));
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
        $this->assertSame('guard', AP_Settings::sanitizeRegistrationCaptcha('guard'));
        $this->assertSame('off', AP_Settings::sanitizeRegistrationCaptcha('turnstile'));
        $this->assertSame('1', AP_Settings::sanitizeCheckbox('on'));
        $this->assertSame('1', AP_Settings::sanitizeCheckbox('1'));
        $this->assertSame('0', AP_Settings::sanitizeCheckbox(null));
        $this->assertSame('0', AP_Settings::sanitizeCheckbox('0'));

        $this->assertSame('https://example.com', AP_Settings::sanitizeUrlOption('https://example.com/'));
        $this->assertSame('', AP_Settings::sanitizeUrlOption('javascript:alert(1)'));
        $this->assertSame('', AP_Settings::sanitizeUrlOption('not-a-url'));
    }

    public function testReservedUsernamesOptionSaveAndPreserve(): void
    {
        require_once $this->root . '/ap-includes/class-ap-registration.php';

        $this->assertSame('', AP_Settings::sanitizeReservedUsernames(''));
        $this->assertSame(
            "news\nBoard",
            AP_Settings::sanitizeReservedUsernames(" news \n\nBoard\r\nNEWS\n  ")
        );
        $this->assertSame(
            'news',
            AP_Settings::sanitizeReservedUsernames([' news ', 'NEWS', '  '])
        );
        $this->assertSame(
            "news\nBoard",
            AP_Settings::sanitizeReservedUsernames("admin\nnews\nADMIN\nBoard\nroot")
        );

        $ok = AP_Options::updateGeneralSettings([
            'blogname' => 'Reserve Site',
            'admin_email' => 'admin@example.test',
            'reserved_usernames' => "news\nBoard\n",
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame(
            "news\nBoard",
            (string) AP_Options::get('reserved_usernames', '', $this->db)
        );
        $this->assertTrue(AP_Registration::isReservedLogin('board', $this->db));

        // Omitted from a later General save → preserved (not wiped).
        $ok2 = AP_Options::updateGeneralSettings([
            'blogname' => 'Reserve Site',
            'admin_email' => 'admin@example.test',
            'registration_captcha' => 'off',
        ], $this->db);
        $this->assertTrue($ok2);
        $this->assertSame(
            "news\nBoard",
            (string) AP_Options::get('reserved_usernames', '', $this->db)
        );

        $cleared = AP_Options::updateGeneralSettings([
            'blogname' => 'Reserve Site',
            'admin_email' => 'admin@example.test',
            'reserved_usernames' => '',
        ], $this->db);
        $this->assertTrue($cleared);
        $this->assertSame('', (string) AP_Options::get('reserved_usernames', 'x', $this->db));
        $this->assertFalse(AP_Registration::isReservedLogin('board', $this->db));
    }

    public function testGeneralScreenHasReservedUsernamesTextarea(): void
    {
        $path = $this->root . '/ap-admin/options-general.php';
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('name="reserved_usernames"', $src);
        $this->assertStringContainsString('<textarea', $src);
        $this->assertStringContainsString('One username per line', $src);
        $this->assertStringContainsString('That username is not available.', $src);
        $this->assertStringContainsString('does not say a name is reserved', $src);
        $this->assertStringContainsString('Users → Add', $src);
        $this->assertStringContainsString('ap-cli user create', $src);
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
            'options-mail.php',
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
                'max_image_display_width',
                'custom_css',
                'uploads_use_yearmonth_folders',
                'use_smilies',
                'site_icon',
                'mail_transport',
                'mail_from_email',
                'mail_reply_to',
                'smtp_host',
                'smtp_encryption',
                'smtp_pass',
                'mail_last_error',
                'reserved_usernames',
            ] as $opt
        ) {
            $this->assertStringContainsString("'" . $opt . "'", $src);
        }
        $this->assertStringContainsString("'smtp_pass' || \$name === 'mail_last_error'", $src);
    }

    public function testGeneralScreenHasSiteIconField(): void
    {
        $path = $this->root . '/ap-admin/options-general.php';
        $this->assertFileExists($path);
        $src = (string) file_get_contents($path);
        // Media picker: upload + library + preview + remove (not a bare ID input).
        $this->assertStringContainsString('renderSiteIconField', $src);
        $this->assertStringContainsString('processSiteIconSave', $src);
        $this->assertStringContainsString('siteIconPickerScript', $src);
        $this->assertStringContainsString('siteIcon', $src);
        $this->assertStringContainsString('multipart/form-data', $src);
        $this->assertStringContainsString('enctype', $src);
        // Save gated by manage_options (page) + processSiteIconSave (nonce + cap).
        $this->assertStringContainsString("requireCapability('manage_options')", $src);
        $this->assertStringContainsString("message_key'] === 'nonce'", $src);
        $this->assertStringContainsString("message_key'] === 'cap'", $src);
    }

    public function testBootstrapLoadsSettingsApi(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-includes/bootstrap.php');
        $this->assertStringContainsString('class-ap-settings.php', $src);
        $this->assertStringContainsString('registerCore', $src);
    }

    public function testUpdateMailSettingsAndWriteOnlyPassword(): void
    {
        $ok = AP_Options::updateMailSettings([
            'mail_from_name' => 'Noreply',
            'mail_from_email' => 'noreply@example.com',
            'mail_reply_to' => 'desk@example.com',
            'mail_transport' => 'SMTP',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '465',
            'smtp_encryption' => 'ssl',
            'smtp_user' => 'user@example.com',
            'smtp_pass' => 's3cret',
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame('Noreply', AP_Options::get('mail_from_name', '', $this->db));
        $this->assertSame('noreply@example.com', AP_Options::get('mail_from_email', '', $this->db));
        $this->assertSame('desk@example.com', AP_Options::get('mail_reply_to', '', $this->db));
        $this->assertSame('smtp', AP_Options::get('mail_transport', '', $this->db));
        $this->assertSame('smtp.example.com', AP_Options::get('smtp_host', '', $this->db));
        $this->assertSame('465', (string) AP_Options::get('smtp_port', '', $this->db));
        $this->assertSame('ssl', AP_Options::get('smtp_encryption', '', $this->db));
        $this->assertSame('user@example.com', AP_Options::get('smtp_user', '', $this->db));
        $this->assertSame('s3cret', AP_Options::get('smtp_pass', '', $this->db));
        $this->assertSame('no', $this->optionAutoload('smtp_pass'));

        $ok2 = AP_Options::updateMailSettings([
            'mail_from_name' => 'Noreply',
            'mail_from_email' => 'noreply@example.com',
            'mail_reply_to' => '',
            'mail_transport' => 'php',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'smtp_user' => 'user@example.com',
            'smtp_pass' => '',
        ], $this->db);
        $this->assertTrue($ok2);
        $this->assertSame('php', AP_Options::get('mail_transport', '', $this->db));
        $this->assertSame('s3cret', AP_Options::get('smtp_pass', '', $this->db));
        $this->assertSame('', AP_Options::get('mail_reply_to', 'x', $this->db));
    }

    public function testMailSettingsSanitizeInvalidValues(): void
    {
        $ok = AP_Options::updateMailSettings([
            'mail_from_name' => '<b>Noreply</b>',
            'mail_from_email' => 'not-an-email',
            'mail_reply_to' => 'also-bad',
            'mail_transport' => 'sendmail',
            'smtp_host' => 'ssl://smtp.example.com',
            'smtp_port' => '99999',
            'smtp_encryption' => 'bogus',
            'smtp_user' => "user\r\nEVIL",
            'smtp_pass' => "secret\nline",
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame('Noreply', AP_Options::get('mail_from_name', '', $this->db));
        $this->assertSame('', AP_Options::get('mail_from_email', 'x', $this->db));
        $this->assertSame('', AP_Options::get('mail_reply_to', 'x', $this->db));
        $this->assertSame('php', AP_Options::get('mail_transport', '', $this->db));
        $this->assertSame('', AP_Options::get('smtp_host', 'x', $this->db));
        $this->assertSame('587', (string) AP_Options::get('smtp_port', '', $this->db));
        $this->assertSame('tls', AP_Options::get('smtp_encryption', '', $this->db));
        $this->assertSame('userEVIL', AP_Options::get('smtp_user', '', $this->db));
        $this->assertSame('secretline', AP_Options::get('smtp_pass', '', $this->db));

        $ok2 = AP_Options::updateMailSettings([
            'mail_from_email' => 'noreply@example.com',
            'mail_reply_to' => 'desk@example.com',
            'mail_transport' => 'smtp',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '0',
            'smtp_encryption' => 'none',
            'smtp_pass' => '',
        ], $this->db);
        $this->assertTrue($ok2);
        $this->assertSame('noreply@example.com', AP_Options::get('mail_from_email', '', $this->db));
        $this->assertSame('desk@example.com', AP_Options::get('mail_reply_to', '', $this->db));
        $this->assertSame('smtp', AP_Options::get('mail_transport', '', $this->db));
        $this->assertSame('smtp.example.com', AP_Options::get('smtp_host', '', $this->db));
        $this->assertSame('587', (string) AP_Options::get('smtp_port', '', $this->db));
        $this->assertSame('none', AP_Options::get('smtp_encryption', '', $this->db));
        $this->assertSame('secretline', AP_Options::get('smtp_pass', '', $this->db));
    }

    public function testGeneralSettingsSaveDoesNotWriteMailOptions(): void
    {
        AP_Options::updateMailSettings([
            'mail_from_email' => 'noreply@example.com',
            'mail_transport' => 'smtp',
            'smtp_host' => 'smtp.example.com',
            'smtp_pass' => 'keep-me',
        ], $this->db);

        $ok = AP_Options::updateGeneralSettings([
            'blogname' => 'General Only',
            'admin_email' => 'admin@example.com',
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame('General Only', AP_Options::get('blogname', '', $this->db));
        $this->assertSame('admin@example.com', AP_Options::get('admin_email', '', $this->db));
        $this->assertSame('noreply@example.com', AP_Options::get('mail_from_email', '', $this->db));
        $this->assertSame('smtp', AP_Options::get('mail_transport', '', $this->db));
        $this->assertSame('smtp.example.com', AP_Options::get('smtp_host', '', $this->db));
        $this->assertSame('keep-me', AP_Options::get('smtp_pass', '', $this->db));
    }

    public function testMailFromEmailIsSeparateFromAdminEmail(): void
    {
        AP_Options::update('admin_email', 'admin@example.com', $this->db);
        AP_Options::update('blogname', 'Example Site', $this->db);
        AP_Options::updateMailSettings([
            'mail_from_name' => 'Example Mail',
            'mail_from_email' => 'noreply@example.com',
            'mail_transport' => 'php',
        ], $this->db);

        $this->assertSame('noreply@example.com', AP_Mail::fromAddress());
        $this->assertSame('Example Mail', AP_Mail::fromName());
        $this->assertSame('admin@example.com', AP_Mail::adminEmail());
        $this->assertSame('admin@example.com', AP_Options::get('admin_email', '', $this->db));

        AP_Options::update('mail_from_email', '', $this->db);
        AP_Options::flushCache();
        $this->assertSame('admin@example.com', AP_Mail::fromAddress());
    }

    public function testMailLastErrorPersistsAndClears(): void
    {
        AP_Mail::enableTestMode();
        $this->assertFalse(AP_Mail::send('not-an-email', 'S', 'B'));
        $this->assertSame('No valid recipients.', AP_Mail::lastError());
        $this->assertSame('No valid recipients.', AP_Mail::storedLastError());
        $this->assertSame('No valid recipients.', AP_Options::get('mail_last_error', '', $this->db));

        $this->assertTrue(AP_Mail::send('user@example.test', 'S', 'B'));
        $this->assertSame('', AP_Mail::lastError());
        $this->assertSame('', AP_Mail::storedLastError());
        $this->assertSame('', (string) AP_Options::get('mail_last_error', 'x', $this->db));
    }

    public function testSendTestToAdminUsesAdminEmail(): void
    {
        AP_Options::update('admin_email', 'admin@example.com', $this->db);
        AP_Options::update('blogname', 'Example Site', $this->db);
        AP_Mail::enableTestMode();

        $this->assertTrue(AP_Mail::sendTestToAdmin());
        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame('admin@example.com', $outbox[0]['to']);
        $this->assertSame('AgoraPress test email', $outbox[0]['subject']);
        $this->assertStringContainsString('Example Site', $outbox[0]['message']);
        $this->assertStringContainsString('text/plain', $outbox[0]['headers']);
    }

    public function testMailScreenIsOwnSettingsGroup(): void
    {
        $mail = (string) file_get_contents($this->root . '/ap-admin/options-mail.php');
        $this->assertStringContainsString("requireCapability('manage_options')", $mail);
        $this->assertStringContainsString("isSaveRequest('mail')", $mail);
        $this->assertStringContainsString("settingsFields('mail')", $mail);
        $this->assertStringContainsString('updateMailSettings', $mail);
        $this->assertStringContainsString('mail_from_name', $mail);
        $this->assertStringContainsString('mail_from_email', $mail);
        $this->assertStringContainsString('mail_reply_to', $mail);
        $this->assertStringContainsString('mail_transport', $mail);
        $this->assertStringContainsString('smtp_host', $mail);
        $this->assertStringContainsString('smtp_port', $mail);
        $this->assertStringContainsString('smtp_encryption', $mail);
        $this->assertStringContainsString('smtp_user', $mail);
        $this->assertStringContainsString('smtp_pass', $mail);
        $this->assertStringContainsString('value=""', $mail);
        $this->assertStringContainsString('autocomplete="new-password"', $mail);
        $this->assertStringContainsString('Send test email to admin_email', $mail);
        $this->assertStringContainsString('ap_mail_send_test', $mail);
        $this->assertStringContainsString('storedLastError', $mail);
        $this->assertStringContainsString("option value=\"none\"", $mail);
        $this->assertStringContainsString("option value=\"tls\"", $mail);
        $this->assertStringContainsString("option value=\"ssl\"", $mail);
        $this->assertStringNotContainsString("isSaveRequest('general')", $mail);

        $general = (string) file_get_contents($this->root . '/ap-admin/options-general.php');
        $this->assertStringNotContainsString('mail_from_email', $general);
        $this->assertStringNotContainsString('smtp_host', $general);
        $this->assertStringNotContainsString('smtp_pass', $general);
        $this->assertStringNotContainsString('ap_mail_send_test', $general);
    }

    public function testSanitizeOptionalEmail(): void
    {
        $this->assertSame('', AP_Settings::sanitizeOptionalEmail(''));
        $this->assertSame('', AP_Settings::sanitizeOptionalEmail('not-an-email'));
        $this->assertSame('user@example.com', AP_Settings::sanitizeOptionalEmail(' user@example.com '));
    }

    public function testMailSettingsPartialUpdateKeepsOmittedFields(): void
    {
        AP_Options::updateMailSettings([
            'mail_from_name' => 'Keep Me',
            'mail_from_email' => 'noreply@example.com',
            'mail_reply_to' => 'desk@example.com',
            'mail_transport' => 'smtp',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '465',
            'smtp_encryption' => 'ssl',
            'smtp_user' => 'user@example.com',
            'smtp_pass' => 'keep-secret',
        ], $this->db);

        $ok = AP_Options::updateMailSettings([
            'mail_transport' => 'php',
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame('php', AP_Options::get('mail_transport', '', $this->db));
        $this->assertSame('Keep Me', AP_Options::get('mail_from_name', '', $this->db));
        $this->assertSame('noreply@example.com', AP_Options::get('mail_from_email', '', $this->db));
        $this->assertSame('desk@example.com', AP_Options::get('mail_reply_to', '', $this->db));
        $this->assertSame('smtp.example.com', AP_Options::get('smtp_host', '', $this->db));
        $this->assertSame('465', (string) AP_Options::get('smtp_port', '', $this->db));
        $this->assertSame('ssl', AP_Options::get('smtp_encryption', '', $this->db));
        $this->assertSame('user@example.com', AP_Options::get('smtp_user', '', $this->db));
        $this->assertSame('keep-secret', AP_Options::get('smtp_pass', '', $this->db));
        $this->assertSame('no', $this->optionAutoload('smtp_pass'));
    }

    private function optionAutoload(string $name): string
    {
        $raw = $this->db->getVar(
            'SELECT autoload FROM ' . $this->db->quoteIdentifier($this->db->table('options'))
            . ' WHERE option_name = ? LIMIT 1',
            [$name]
        );

        return (string) $raw;
    }
}
