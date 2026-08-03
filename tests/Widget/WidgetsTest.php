<?php

/**
 * Tests for AP_Widgets — modular areas, instances, and render.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Widget;

use AP_DB;
use AP_Migrator;
use AP_Nav_Menu;
use AP_Options;
use AP_Post;
use AP_Taxonomy;
use AP_Widgets;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Widgets::class)]
final class WidgetsTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-taxonomy.php';
        require_once $this->root . '/ap-includes/class-ap-nav-menu.php';
        require_once $this->root . '/ap-includes/class-ap-widgets.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Post::resetRegistry();
        AP_Options::flushCache();
        AP_Widgets::reset();
        if (class_exists('AP_Nav_Menu', false) && method_exists('AP_Nav_Menu', 'reset')) {
            AP_Nav_Menu::reset();
        }

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Post::ensureBuiltins();
        if (class_exists('AP_Taxonomy', false)) {
            AP_Taxonomy::ensureBuiltins();
        }

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

        AP_Widgets::registerCore();
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
        AP_Options::flushCache();
        AP_Widgets::reset();
        if (class_exists('AP_Nav_Menu', false) && method_exists('AP_Nav_Menu', 'reset')) {
            AP_Nav_Menu::reset();
        }
        unset($GLOBALS['apdb']);
    }

    public function testRegisterSidebarAndCoreWidgets(): void
    {
        AP_Widgets::registerSidebar('sidebar-1', [
            'name' => 'Primary',
            'description' => 'Main area',
        ]);
        $this->assertTrue(AP_Widgets::isRegisteredSidebar('sidebar-1'));
        $this->assertArrayHasKey('sidebar-1', AP_Widgets::getSidebars());
        $this->assertSame('Primary', AP_Widgets::getSidebar('sidebar-1')['name'] ?? '');

        $types = AP_Widgets::getWidgetTypes();
        foreach (['text', 'recent_posts', 'categories', 'search', 'pages', 'nav_menu'] as $idBase) {
            $this->assertTrue(AP_Widgets::isRegisteredWidget($idBase), $idBase);
            $this->assertArrayHasKey($idBase, $types);
        }
    }

    public function testAddSaveRenderTextWidget(): void
    {
        AP_Widgets::registerSidebar('sidebar-1', ['name' => 'Primary']);

        $id = AP_Widgets::addWidget('text', 'sidebar-1', [
            'title' => 'Hello <script>',
            'text' => "Line one\nLine two",
        ], $this->db);
        $this->assertIsString($id);
        $this->assertSame('text-1', $id);
        $this->assertTrue(AP_Widgets::isActiveSidebar('sidebar-1', $this->db));

        $inst = AP_Widgets::getInstance($id, $this->db);
        $this->assertStringContainsString('Hello', (string) $inst['title']);
        // Title sanitized (tags stripped).
        $this->assertStringNotContainsString('<script>', (string) $inst['title']);

        $html = AP_Widgets::dynamicSidebar('sidebar-1', ['echo' => false], $this->db);
        $this->assertStringContainsString('widget_text', $html);
        $this->assertStringContainsString('id="text-1"', $html);
        $this->assertStringContainsString('Hello', $html);
        $this->assertStringContainsString('Line one', $html);
        $this->assertStringContainsString('<br>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testRecentPostsWidgetListsPublishedPosts(): void
    {
        AP_Widgets::registerSidebar('sidebar-1', ['name' => 'Primary']);
        AP_Post::insert([
            'post_title' => 'Alpha Post',
            'post_content' => 'A',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Beta Post',
            'post_content' => 'B',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $this->db);
        // Draft should not appear.
        AP_Post::insert([
            'post_title' => 'Hidden Draft',
            'post_content' => 'C',
            'post_status' => 'draft',
            'post_type' => 'post',
        ], $this->db);

        $id = AP_Widgets::addWidget('recent_posts', 'sidebar-1', [
            'title' => 'Latest',
            'number' => 5,
        ], $this->db);
        $this->assertIsString($id);

        $html = AP_Widgets::dynamicSidebar('sidebar-1', ['echo' => false], $this->db);
        $this->assertStringContainsString('Latest', $html);
        $this->assertStringContainsString('Alpha Post', $html);
        $this->assertStringContainsString('Beta Post', $html);
        $this->assertStringNotContainsString('Hidden Draft', $html);
    }

    public function testMoveReorderAndRemove(): void
    {
        AP_Widgets::registerSidebar('sidebar-1', ['name' => 'Primary']);
        AP_Widgets::registerSidebar('footer-1', ['name' => 'Footer']);

        $a = AP_Widgets::addWidget('text', 'sidebar-1', ['title' => 'A', 'text' => 'a'], $this->db);
        $b = AP_Widgets::addWidget('search', 'sidebar-1', ['title' => 'Find'], $this->db);
        $this->assertIsString($a);
        $this->assertIsString($b);

        $this->assertSame([$a, $b], AP_Widgets::getWidgetsForSidebar('sidebar-1', $this->db));

        $this->assertTrue(AP_Widgets::reorderSidebar('sidebar-1', [$b, $a], $this->db));
        $this->assertSame([$b, $a], AP_Widgets::getWidgetsForSidebar('sidebar-1', $this->db));

        $this->assertTrue(AP_Widgets::moveWidget($a, 'footer-1', null, $this->db));
        $this->assertSame([$b], AP_Widgets::getWidgetsForSidebar('sidebar-1', $this->db));
        $this->assertSame([$a], AP_Widgets::getWidgetsForSidebar('footer-1', $this->db));
        $this->assertTrue(AP_Widgets::isActiveSidebar('footer-1', $this->db));

        $this->assertTrue(AP_Widgets::removeWidget($a, $this->db));
        $this->assertSame([], AP_Widgets::getWidgetsForSidebar('footer-1', $this->db));
        $this->assertFalse(AP_Widgets::isActiveSidebar('footer-1', $this->db));
        // Instance settings removed.
        $settings = AP_Widgets::getWidgetSettings('text', $this->db);
        $parsed = AP_Widgets::parseWidgetId($a);
        $this->assertNotNull($parsed);
        $this->assertArrayNotHasKey($parsed['number'], $settings);
    }

    public function testCustomWidgetRegistrationAndProceduralApi(): void
    {
        AP_Widgets::registerSidebar('sidebar-1', ['name' => 'Primary']);
        AP_Widgets::registerWidget('hello', [
            'name' => 'Hello',
            'description' => 'Says hello',
            'defaults' => ['who' => 'world'],
            'form_fields' => [
                'who' => ['label' => 'Who', 'type' => 'text'],
            ],
            'render_callback' => static function (array $instance, array $args): string {
                return (string) ($args['before_widget'] ?? '')
                    . 'Hi ' . ap_esc_html((string) ($instance['who'] ?? ''))
                    . (string) ($args['after_widget'] ?? '');
            },
        ]);

        ap_register_sidebar('custom-area', ['name' => 'Custom']);
        $this->assertTrue(AP_Widgets::isRegisteredSidebar('custom-area'));
        $this->assertArrayHasKey('custom-area', ap_get_sidebars());

        $wid = AP_Widgets::addWidget('hello', 'sidebar-1', ['who' => 'Ada'], $this->db);
        $this->assertIsString($wid);
        $this->assertTrue(ap_is_active_sidebar('sidebar-1', $this->db));

        $html = ap_dynamic_sidebar('sidebar-1', ['echo' => false], $this->db);
        $this->assertStringContainsString('Hi Ada', $html);
    }

    public function testParseWidgetIdAndSanitize(): void
    {
        $this->assertSame(
            ['id_base' => 'recent_posts', 'number' => 12],
            AP_Widgets::parseWidgetId('recent_posts-12')
        );
        $this->assertNull(AP_Widgets::parseWidgetId('bad'));
        $this->assertNull(AP_Widgets::parseWidgetId('text-0'));
        $this->assertSame('sidebar-1', AP_Widgets::sanitizeId('SideBar-1!'));
        $this->assertSame('text-2script', AP_Widgets::sanitizeWidgetId('Text-2<script>'));
        $this->assertSame('text-2', AP_Widgets::sanitizeWidgetId('Text-2'));
    }

    public function testInactiveSidebarNotActive(): void
    {
        AP_Widgets::registerSidebar('sidebar-1', ['name' => 'Primary']);
        $this->assertFalse(AP_Widgets::isActiveSidebar('sidebar-1', $this->db));
        $this->assertFalse(AP_Widgets::isActiveSidebar('missing', $this->db));
        $this->assertSame('', AP_Widgets::dynamicSidebar('sidebar-1', ['echo' => false], $this->db));
    }

    public function testNavMenuWidgetRendersAssignedMenu(): void
    {
        AP_Widgets::registerSidebar('footer-1', ['name' => 'Footer']);
        AP_Nav_Menu::saveMenu('main', 'Main', [
            ['type' => 'custom', 'title' => 'Docs', 'url' => 'https://example.test/docs'],
        ], $this->db);

        $id = AP_Widgets::addWidget('nav_menu', 'footer-1', [
            'title' => 'Links',
            'menu' => 'main',
        ], $this->db);
        $this->assertIsString($id);

        $html = AP_Widgets::dynamicSidebar('footer-1', ['echo' => false], $this->db);
        $this->assertStringContainsString('Links', $html);
        $this->assertStringContainsString('Docs', $html);
        $this->assertStringContainsString('example.test/docs', $html);
    }
}
