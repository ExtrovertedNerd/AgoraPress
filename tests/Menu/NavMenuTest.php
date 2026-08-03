<?php

/**
 * Tests for AP_Nav_Menu — locations, persistence, and HTML render.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Menu;

use AP_DB;
use AP_Migrator;
use AP_Nav_Menu;
use AP_Options;
use AP_Post;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Nav_Menu::class)]
final class NavMenuTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-nav-menu.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Post::resetRegistry();
        AP_Options::flushCache();
        AP_Nav_Menu::reset();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Post::ensureBuiltins();

        $this->db->insert('options', [
            'option_name' => 'home',
            'option_value' => 'https://example.test',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'siteurl',
            'option_value' => 'https://example.test',
            'autoload' => 'yes',
        ]);

        $GLOBALS['apdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
        AP_Options::flushCache();
        AP_Nav_Menu::reset();
        unset($GLOBALS['apdb']);
    }

    public function testRegisterLocationsAndAssignMenu(): void
    {
        AP_Nav_Menu::registerLocations([
            'primary' => 'Primary',
            'footer' => 'Footer',
        ]);
        $locs = AP_Nav_Menu::getRegisteredLocations();
        $this->assertArrayHasKey('primary', $locs);
        $this->assertArrayHasKey('footer', $locs);

        $this->assertTrue(AP_Nav_Menu::saveMenu('main', 'Main Menu', [
            ['type' => 'custom', 'title' => 'Home', 'url' => 'https://example.test/'],
            ['type' => 'custom', 'title' => 'Docs', 'url' => 'https://example.test/docs'],
        ], $this->db));

        $menu = AP_Nav_Menu::getMenu('main', $this->db);
        $this->assertNotNull($menu);
        $this->assertSame('Main Menu', $menu['name']);
        $this->assertCount(2, $menu['items']);

        $this->assertTrue(AP_Nav_Menu::setLocationAssignments([
            'primary' => 'main',
            'footer' => '',
        ], $this->db));
        $this->assertSame('main', AP_Nav_Menu::getMenuSlugForLocation('primary', $this->db));
        $this->assertTrue(AP_Nav_Menu::hasNavMenu('primary', $this->db));
        $this->assertFalse(AP_Nav_Menu::hasNavMenu('footer', $this->db));
    }

    public function testRenderProducesEscapedHtml(): void
    {
        AP_Nav_Menu::registerLocation('primary', 'Primary');
        AP_Nav_Menu::saveMenu('main', 'Main', [
            [
                'type' => 'custom',
                'title' => 'Click <me>',
                'url' => 'https://example.test/path?a=1&b=2',
            ],
            [
                'type' => 'custom',
                'title' => 'External',
                'url' => 'https://other.test/',
                'target' => '_blank',
            ],
        ], $this->db);
        AP_Nav_Menu::setLocationAssignments(['primary' => 'main'], $this->db);

        $html = AP_Nav_Menu::render([
            'theme_location' => 'primary',
            'echo' => false,
            'container_class' => 'ap-nav ap-nav--primary',
            'menu_class' => 'ap-menu',
        ], $this->db);

        $this->assertStringContainsString('<nav', $html);
        $this->assertStringContainsString('ap-nav--primary', $html);
        $this->assertStringContainsString('Click &lt;me&gt;', $html);
        $this->assertStringNotContainsString('Click <me>', $html);
        $this->assertStringContainsString('href="https://example.test/path?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('aria-label="Main"', $html);
    }

    public function testPageItemResolvesTitleAndUrl(): void
    {
        $pageId = AP_Post::insert([
            'post_title' => 'About Us',
            'post_content' => 'About',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'about',
        ], $this->db);

        AP_Nav_Menu::saveMenu('pages', 'Pages', [
            ['type' => 'page', 'title' => '', 'object_id' => $pageId],
        ], $this->db);

        $menu = AP_Nav_Menu::getMenu('pages', $this->db);
        $this->assertNotNull($menu);
        $item = $menu['items'][0];
        $this->assertSame('About Us', AP_Nav_Menu::itemTitle($item, $this->db));
        $url = AP_Nav_Menu::itemUrl($item, $this->db);
        $this->assertStringContainsString((string) $pageId, $url);
    }

    public function testDeleteMenuClearsAssignments(): void
    {
        AP_Nav_Menu::registerLocation('primary', 'Primary');
        AP_Nav_Menu::saveMenu('temp', 'Temp', [
            ['type' => 'custom', 'title' => 'X', 'url' => '/x'],
        ], $this->db);
        AP_Nav_Menu::setLocationAssignments(['primary' => 'temp'], $this->db);

        $this->assertTrue(AP_Nav_Menu::deleteMenu('temp', $this->db));
        $this->assertNull(AP_Nav_Menu::getMenu('temp', $this->db));
        $this->assertSame('', AP_Nav_Menu::getMenuSlugForLocation('primary', $this->db));
        $this->assertFalse(AP_Nav_Menu::hasNavMenu('primary', $this->db));
    }

    public function testProceduralHelpers(): void
    {
        ap_register_nav_menus(['sidebar' => 'Sidebar']);
        $this->assertArrayHasKey('sidebar', AP_Nav_Menu::getRegisteredLocations());

        $this->assertTrue(ap_save_nav_menu('side', 'Side', [
            ['type' => 'custom', 'title' => 'Link', 'url' => '/side'],
        ], $this->db));
        AP_Nav_Menu::setLocationAssignments(['sidebar' => 'side'], $this->db);
        $this->assertTrue(ap_has_nav_menu('sidebar', $this->db));

        $html = ap_nav_menu([
            'theme_location' => 'sidebar',
            'echo' => false,
        ], $this->db);
        $this->assertStringContainsString('Link', $html);
    }

    public function testEmptyMenuUsesFallbackCallback(): void
    {
        $html = AP_Nav_Menu::render([
            'theme_location' => 'missing',
            'echo' => false,
            'fallback_cb' => static function (): string {
                return '<!--fallback-->';
            },
        ], $this->db);
        $this->assertSame('<!--fallback-->', $html);
    }

    public function testInvalidItemsAreDropped(): void
    {
        $this->assertTrue(AP_Nav_Menu::saveMenu('clean', 'Clean', [
            ['type' => 'custom', 'title' => '', 'url' => ''],
            ['type' => 'page', 'object_id' => 0],
            ['type' => 'custom', 'title' => 'Ok', 'url' => '/ok'],
        ], $this->db));
        $menu = AP_Nav_Menu::getMenu('clean', $this->db);
        $this->assertNotNull($menu);
        $this->assertCount(1, $menu['items']);
        $this->assertSame('Ok', $menu['items'][0]['title']);
    }
}
