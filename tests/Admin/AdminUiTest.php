<?php

/**
 * Tests for the clean responsive admin shell (header, CSS, menu helpers).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Admin;
use AP_DB;
use AP_Options;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Admin::class)]
final class AdminUiTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';

        if (!defined('AP_ABSPATH')) {
            define('AP_ABSPATH', $this->root . '/');
        }

        AP_Options::flushCache();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        // Minimal options table for siteName / homeUrl helpers.
        $this->db->query(
            'CREATE TABLE ' . $this->db->quoteIdentifier($this->db->table('options')) . ' (
                option_id INTEGER PRIMARY KEY AUTOINCREMENT,
                option_name TEXT NOT NULL UNIQUE,
                option_value TEXT NOT NULL DEFAULT \'\',
                autoload TEXT NOT NULL DEFAULT \'yes\'
            )'
        );
    }

    protected function tearDown(): void
    {
        AP_Options::flushCache();
    }

    public function testShellFilesExist(): void
    {
        $files = [
            'ap-admin/admin-header.php',
            'ap-admin/admin-footer.php',
            'ap-admin/css/admin.css',
        ];
        foreach ($files as $rel) {
            $this->assertFileIsReadable($this->root . '/' . $rel, "Missing {$rel}");
        }
    }

    public function testHeaderHasResponsiveShellMarkup(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/admin-header.php');
        $needles = [
            'viewport',
            'skip-link',
            'ap-menu-toggle',
            'aria-controls="ap-admin-menu"',
            'aria-expanded',
            'ap-admin-menu',
            'ap-admin-menu-backdrop',
            'Visit Site',
            'ap-visit-site',
            'AP_Admin::siteName',
            'AP_Admin::homeUrl',
            'ap-menu-section',
            'role="banner"',
            'id="ap-admin-content"',
            'ap_nonce_url',
            'log-out',
            'ap-color-mode-toggle',
            'data-ap-color-mode-pref',
            'color-scheme',
            'ap_admin_color_mode',
            'AP_Admin::getColorMode',
        ];
        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $src, "Header missing {$needle}");
        }
    }

    public function testFooterHasMenuToggleScriptAndVersion(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/admin-footer.php');
        $needles = [
            'ap-menu-open',
            'ap-menu-toggle',
            'ap-admin-menu-backdrop',
            'Escape',
            'ap-footer-version',
            'Thank you for creating with',
            'role="contentinfo"',
            'ap_admin_color_mode',
            'ap-color-mode-toggle',
            'localStorage',
            "nextColorMode",
        ];
        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $src, "Footer missing {$needle}");
        }
    }

    public function testAdminCssIsResponsive(): void
    {
        $css = (string) file_get_contents($this->root . '/ap-admin/css/admin.css');
        $needles = [
            'prefers-color-scheme: dark',
            'data-ap-color-mode="light"',
            'data-ap-color-mode="dark"',
            '@media (max-width: 782px)',
            '@media (max-width: 480px)',
            'ap-menu-toggle',
            'ap-menu-open',
            'ap-admin-menu-backdrop',
            'ap-color-mode-toggle',
            'position: sticky',
            'overflow-x: auto',
            'ap-dashboard-cards',
            'ap-dashboard-grid',
            'ap-glance-list',
            'prefers-reduced-motion',
            'prefers-contrast: more',
            'min-width: 36rem',
            'hover: none',
            '@media print',
            'ap-form-table',
            'optimizeLegibility',
            '--ap-radius-lg',
            '--ap-primary-soft',
        ];
        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $css, "CSS missing {$needle}");
        }
    }

    public function testColorModeHelpers(): void
    {
        $this->assertSame(['auto', 'light', 'dark'], AP_Admin::colorModes());
        $this->assertSame('auto', AP_Admin::sanitizeColorMode(''));
        $this->assertSame('auto', AP_Admin::sanitizeColorMode('neon'));
        $this->assertSame('light', AP_Admin::sanitizeColorMode('LIGHT'));
        $this->assertSame('dark', AP_Admin::sanitizeColorMode('dark'));
        $this->assertSame('light', AP_Admin::nextColorMode('auto'));
        $this->assertSame('dark', AP_Admin::nextColorMode('light'));
        $this->assertSame('auto', AP_Admin::nextColorMode('dark'));

        $labels = AP_Admin::colorModeLabels();
        $this->assertArrayHasKey('auto', $labels);
        $this->assertArrayHasKey('light', $labels);
        $this->assertArrayHasKey('dark', $labels);

        // Without usermeta table / user, defaults to auto.
        $this->assertSame('auto', AP_Admin::getColorMode(0, $this->db));
        $this->assertSame('auto', AP_Admin::getColorMode(null, $this->db));
    }

    public function testColorModeUserMetaPersistence(): void
    {
        require_once $this->root . '/ap-includes/class-ap-user.php';

        $this->db->query(
            'CREATE TABLE ' . $this->db->quoteIdentifier($this->db->table('usermeta')) . ' (
                umeta_id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                meta_key TEXT NOT NULL,
                meta_value TEXT NOT NULL DEFAULT \'\'
            )'
        );

        $this->assertTrue(AP_Admin::setColorMode(7, 'dark', $this->db));
        $this->assertSame('dark', AP_Admin::getColorMode(7, $this->db));
        $this->assertTrue(AP_Admin::setColorMode(7, 'light', $this->db));
        $this->assertSame('light', AP_Admin::getColorMode(7, $this->db));
        $this->assertTrue(AP_Admin::setColorMode(7, 'bogus', $this->db));
        $this->assertSame('auto', AP_Admin::getColorMode(7, $this->db));
        $this->assertFalse(AP_Admin::setColorMode(0, 'dark', $this->db));
    }

    public function testProfileFormHasAdminAppearanceFieldset(): void
    {
        $src = (string) file_get_contents(
            $this->root . '/ap-admin/includes/class-ap-admin-user-edit.php'
        );
        $this->assertStringContainsString('renderColorModeFieldset', $src);
        $this->assertStringContainsString('Admin Appearance', $src);
        $this->assertStringContainsString('ap_admin_color_mode', $src);
        $this->assertStringContainsString("mode === 'profile'", $src);
    }

    public function testLoginPageHasColorModeShell(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/login.php');
        $needles = [
            'ap-color-mode-toggle',
            'data-ap-color-mode-pref',
            'color-scheme',
            'ap_admin_color_mode',
            'ap-login-tagline',
            'ap-admin-login',
        ];
        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $src, "Login missing {$needle}");
        }
    }

    public function testSiteNameAndHomeUrlHelpers(): void
    {
        $this->assertSame('AgoraPress', AP_Admin::siteName($this->db));

        AP_Options::update('blogname', 'Demo Agora', $this->db);
        $this->assertSame('Demo Agora', AP_Admin::siteName($this->db));

        $home = AP_Admin::homeUrl($this->db);
        $this->assertNotSame('', $home);
        $this->assertIsString($home);
    }

    public function testMenuSectionLabels(): void
    {
        $this->assertSame('Content', AP_Admin::menuSectionLabel('content'));
        $this->assertSame('Appearance', AP_Admin::menuSectionLabel('appearance'));
        $this->assertSame('Users', AP_Admin::menuSectionLabel('users'));
        $this->assertSame('Settings', AP_Admin::menuSectionLabel('settings'));
        $this->assertSame('', AP_Admin::menuSectionLabel('unknown'));
        $this->assertSame('', AP_Admin::menuSectionLabel(''));
    }

    public function testMenuItemsHaveSections(): void
    {
        $items = AP_Admin::menuItems('posts');
        $this->assertNotEmpty($items);

        $byId = [];
        foreach ($items as $item) {
            $byId[$item['id']] = $item;
            $this->assertArrayHasKey('section', $item);
        }

        $this->assertArrayHasKey('dashboard', $byId);
        $this->assertSame('', $byId['dashboard']['section']);
        $this->assertTrue($byId['posts']['active']);
        $this->assertSame('content', $byId['posts']['section']);
        $this->assertSame('settings', $byId['options-general']['section']);
        $this->assertSame('appearance', $byId['theme-options']['section']);
        $this->assertSame('users', $byId['profile']['section']);
    }

    public function testDashboardUsesCssGridClass(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/index.php');
        $this->assertStringContainsString('ap-dashboard-grid', $src);
        $this->assertStringNotContainsString('style="display:grid', $src);
        $this->assertStringContainsString('ap-dashboard-widget', $src);
        $this->assertStringContainsString('At a Glance', $src);
        $this->assertStringContainsString('Activity', $src);
        $this->assertStringContainsString('Quick Draft', $src);
        $this->assertStringContainsString('AP_Admin_Dashboard', $src);
    }
}
