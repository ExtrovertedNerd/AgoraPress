<?php

/**
 * Tests for theme options registration + theme_mods API.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Theme;

use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Settings;
use AP_Theme;
use PDO;
use PHPUnit\Framework\TestCase;

final class ThemeOptionsApiTest extends TestCase
{
    private string $root;

    private string $tempThemes;

    private string $themeSlug = 'opts-theme';

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-settings.php';
        require_once $this->root . '/ap-includes/class-ap-theme.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Options::flushCache();
        AP_Settings::flush();
        AP_Theme::reset();

        $this->tempThemes = sys_get_temp_dir() . '/ap-theme-opts-' . bin2hex(random_bytes(4));
        mkdir($this->tempThemes, 0700, true);
        // Unique slug per process so functions.php helper name cannot collide.
        $slug = 'opts' . substr(bin2hex(random_bytes(3)), 0, 6);
        $this->themeSlug = $slug;
        mkdir($this->tempThemes . '/' . $slug, 0700, true);
        file_put_contents(
            $this->tempThemes . '/' . $slug . '/style.css',
            "/*\nTheme Name: Options Theme\nVersion: 1.0\n*/\n"
        );
        file_put_contents(
            $this->tempThemes . '/' . $slug . '/index.php',
            "<?php\n// theme\n"
        );
        $hookFn = str_replace('-', '_', $slug) . '_register_theme_hooks';
        $php = <<<PHP
<?php
declare(strict_types=1);
if (!function_exists('{$hookFn}')) {
    function {$hookFn}(): void
    {
        if (!function_exists('ap_add_action')) {
            return;
        }
        ap_add_action('ap_theme_options_register', static function (): void {
            if (function_exists('ap_get_stylesheet') && ap_get_stylesheet() !== '{$slug}') {
                return;
            }
            \$group = AP_Theme::THEME_OPTIONS_GROUP;
            \$page = AP_Theme::THEME_OPTIONS_PAGE;
            ap_register_setting(\$group, 'opts_theme_flag', [
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => static function (mixed \$v): string {
                    return strtoupper(trim((string) (\$v ?? '')));
                },
            ]);
            ap_add_settings_section('opts_main', 'Opts main', null, \$page);
            ap_add_settings_field(
                'opts_theme_flag',
                'Flag',
                static function (): void {
                    \$v = (string) ap_get_option('opts_theme_flag', '');
                    echo '<input name="opts_theme_flag" value="' . ap_esc_attr(\$v) . '">';
                },
                \$page,
                'opts_main'
            );
        });
    }
}
{$hookFn}();
PHP;
        file_put_contents($this->tempThemes . '/' . $slug . '/functions.php', $php);

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();
        $GLOBALS['apdb'] = $this->db;

        AP_Theme::setThemesRootOverride($this->tempThemes);
        AP_Theme::setActiveOverride($this->themeSlug, $this->themeSlug);
    }

    protected function tearDown(): void
    {
        AP_Options::flushCache();
        AP_Settings::flush();
        AP_Theme::reset();
        unset($GLOBALS['apdb']);
        $this->removeDir($this->tempThemes);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    public function testRegisterThemeOptionsLoadsThemeAndFiresHook(): void
    {
        $this->assertFalse(AP_Theme::hasRegisteredThemeOptions());
        AP_Theme::registerThemeOptions($this->db);
        $hookFn = str_replace('-', '_', $this->themeSlug) . '_register_theme_hooks';
        $this->assertTrue(function_exists($hookFn));
        $this->assertTrue(AP_Theme::hasRegisteredThemeOptions());
        $sections = AP_Settings::getSections(AP_Theme::THEME_OPTIONS_PAGE);
        $this->assertArrayHasKey('opts_main', $sections);
        $settings = AP_Settings::getRegisteredSettings(AP_Theme::THEME_OPTIONS_GROUP);
        $this->assertArrayHasKey('opts_theme_flag', $settings);
    }

    public function testThemeOptionsSaveViaSettingsApi(): void
    {
        AP_Theme::registerThemeOptions($this->db);
        $ok = AP_Settings::save(AP_Theme::THEME_OPTIONS_GROUP, [
            'opts_theme_flag' => '  hello  ',
        ], $this->db);
        $this->assertTrue($ok);
        $this->assertSame('HELLO', AP_Options::get('opts_theme_flag', '', $this->db));
    }

    public function testThemeModsGetSetRemove(): void
    {
        $slug = $this->themeSlug;
        $this->assertSame('default', ap_get_theme_mod('accent', 'default', $slug, $this->db));
        $this->assertTrue(ap_set_theme_mod('accent', 'cyan', $slug, $this->db));
        $this->assertSame('cyan', ap_get_theme_mod('accent', 'default', $slug, $this->db));
        $mods = ap_get_theme_mods($slug, $this->db);
        $this->assertSame('cyan', $mods['accent'] ?? null);
        $this->assertTrue(ap_remove_theme_mod('accent', $slug, $this->db));
        $this->assertSame('default', ap_get_theme_mod('accent', 'default', $slug, $this->db));
        $this->assertSame('theme_mods_' . $slug, AP_Theme::themeModsOptionName($slug));
    }

    public function testThemeOptionsScreenDocumentsRegistration(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/theme-options.php');
        $this->assertStringContainsString('ap_theme_options_register', $src);
        $this->assertStringContainsString('registerThemeOptions', $src);
        $this->assertStringContainsString('ap_do_settings_sections', $src);
        $this->assertStringContainsString('THEME_OPTIONS', $src);
        // No hard-coded ExtrovertedNerd project save path.
        $this->assertStringNotContainsString('en_set_hub_projects', $src);
    }

}
