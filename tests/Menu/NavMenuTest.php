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

    public function testPublishedPageAppearsInPrimaryNavRender(): void
    {
        $pageId = AP_Post::insert([
            'post_title' => 'About Us',
            'post_content' => 'About body',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'about-nav',
        ], $this->db);
        $this->assertGreaterThan(0, $pageId);

        AP_Nav_Menu::registerLocation('primary', 'Primary');
        AP_Nav_Menu::saveMenu('main', 'Main', [
            ['type' => 'page', 'title' => '', 'object_id' => $pageId],
            ['type' => 'custom', 'title' => 'Docs', 'url' => '/docs'],
        ], $this->db);
        AP_Nav_Menu::setLocationAssignments(['primary' => 'main'], $this->db);

        $html = AP_Nav_Menu::render([
            'theme_location' => 'primary',
            'echo' => false,
            'container_class' => 'ap-nav ap-nav--primary',
        ], $this->db);

        $this->assertStringContainsString('About Us', $html);
        $this->assertStringContainsString('menu-item-type-page', $html);
        $this->assertStringContainsString((string) $pageId, $html);
        $this->assertStringContainsString('Docs', $html);
        $this->assertStringContainsString('ap-nav--primary', $html);
    }

    public function testDraftPageIsHiddenFromRenderedNav(): void
    {
        $draftId = AP_Post::insert([
            'post_title' => 'Secret Draft',
            'post_content' => 'Nope',
            'post_status' => 'draft',
            'post_type' => 'page',
            'post_name' => 'secret-draft',
        ], $this->db);
        $pubId = AP_Post::insert([
            'post_title' => 'Public Page',
            'post_content' => 'Yes',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'public-page',
        ], $this->db);

        AP_Nav_Menu::saveMenu('mixed', 'Mixed', [
            ['type' => 'page', 'title' => '', 'object_id' => $draftId],
            ['type' => 'page', 'title' => '', 'object_id' => $pubId],
        ], $this->db);

        $html = AP_Nav_Menu::render([
            'menu' => 'mixed',
            'echo' => false,
        ], $this->db);

        $this->assertStringContainsString('Public Page', $html);
        $this->assertStringNotContainsString('Secret Draft', $html);
        $this->assertFalse(AP_Nav_Menu::isItemVisible([
            'type' => 'page',
            'object_id' => $draftId,
        ], $this->db));
        $this->assertTrue(AP_Nav_Menu::isItemVisible([
            'type' => 'page',
            'object_id' => $pubId,
        ], $this->db));
    }

    public function testFallbackPrimaryListsPublishedPages(): void
    {
        AP_Post::insert([
            'post_title' => 'About',
            'post_content' => 'About',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'about-fallback',
            'menu_order' => 1,
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Contact',
            'post_content' => 'Hi',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'contact-fallback',
            'menu_order' => 2,
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Hidden Draft',
            'post_content' => 'No',
            'post_status' => 'draft',
            'post_type' => 'page',
            'post_name' => 'draft-fallback',
            'menu_order' => 0,
        ], $this->db);

        $html = AP_Nav_Menu::fallbackPrimary([
            'echo' => false,
            'container_class' => 'ap-nav ap-nav--primary',
            'include_forums' => false,
        ], $this->db);

        $this->assertStringContainsString('ap-nav--primary', $html);
        $this->assertStringContainsString('Home', $html);
        $this->assertStringContainsString('About', $html);
        $this->assertStringContainsString('Contact', $html);
        $this->assertStringContainsString('menu-item-type-page', $html);
        $this->assertStringNotContainsString('Hidden Draft', $html);

        // Used as render() fallback when primary has no menu.
        AP_Nav_Menu::registerLocation('primary', 'Primary');
        $viaRender = AP_Nav_Menu::render([
            'theme_location' => 'primary',
            'echo' => false,
            'container_class' => 'ap-nav ap-nav--primary',
            'fallback_cb' => [AP_Nav_Menu::class, 'fallbackPrimary'],
        ], $this->db);
        $this->assertStringContainsString('About', $viaRender);
        $this->assertStringContainsString('Contact', $viaRender);
    }

    public function testGetPublishedPagesReturnsOnlyPublishStatus(): void
    {
        AP_Post::insert([
            'post_title' => 'Live',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'live-p',
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Drafty',
            'post_status' => 'draft',
            'post_type' => 'page',
            'post_name' => 'draft-p',
        ], $this->db);

        $pages = AP_Nav_Menu::getPublishedPages($this->db);
        $titles = array_map(static fn (AP_Post $p): string => (string) $p->post_title, $pages);
        $this->assertContains('Live', $titles);
        $this->assertNotContains('Drafty', $titles);
    }

    public function testGetPublishedPagesHonorsShowInNavMeta(): void
    {
        $shownId = AP_Post::insert([
            'post_title' => 'Shown In Nav',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'shown-nav',
        ], $this->db);
        $hiddenId = AP_Post::insert([
            'post_title' => 'Hidden From Nav',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'hidden-nav',
        ], $this->db);
        $this->assertGreaterThan(0, $shownId);
        $this->assertGreaterThan(0, $hiddenId);

        // Default (missing meta) = show.
        $this->assertTrue(AP_Post::showsInNav($shownId, $this->db));
        $this->assertTrue(AP_Post::setShowInNav($hiddenId, false, $this->db));
        $this->assertFalse(AP_Post::showsInNav($hiddenId, $this->db));

        $pages = AP_Nav_Menu::getPublishedPages($this->db);
        $titles = array_map(static fn (AP_Post $p): string => (string) $p->post_title, $pages);
        $this->assertContains('Shown In Nav', $titles);
        $this->assertNotContains('Hidden From Nav', $titles);

        $html = AP_Nav_Menu::fallbackPrimary([
            'echo' => false,
            'include_forums' => false,
            'include_home' => false,
        ], $this->db);
        $this->assertStringContainsString('Shown In Nav', $html);
        $this->assertStringNotContainsString('Hidden From Nav', $html);

        // Re-enable restores automatic inclusion.
        $this->assertTrue(AP_Post::setShowInNav($hiddenId, true, $this->db));
        $this->assertTrue(AP_Post::showsInNav($hiddenId, $this->db));
        $titles2 = array_map(
            static fn (AP_Post $p): string => (string) $p->post_title,
            AP_Nav_Menu::getPublishedPages($this->db)
        );
        $this->assertContains('Hidden From Nav', $titles2);
    }

    public function testInsertPageShowInNavDataKey(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'No Nav',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'no-nav-key',
            'show_in_nav' => false,
        ], $this->db);
        $this->assertGreaterThan(0, $id);
        $this->assertFalse(AP_Post::showsInNav($id, $this->db));

        $this->assertTrue(AP_Post::update($id, ['show_in_nav' => true], $this->db));
        $this->assertTrue(AP_Post::showsInNav($id, $this->db));
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

    public function testItemsFromAdminPostAddsPickersAndCustomLink(): void
    {
        $pageId = AP_Post::insert([
            'post_title' => 'Contact',
            'post_content' => 'Hi',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'contact',
        ], $this->db);
        $postId = AP_Post::insert([
            'post_title' => 'Hello',
            'post_content' => 'World',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_name' => 'hello',
        ], $this->db);

        $raw = AP_Nav_Menu::itemsFromAdminPost([
            'item_title' => ['0' => 'Home', '1' => 'Drop me'],
            'item_type' => ['0' => 'custom', '1' => 'custom'],
            'item_url' => ['0' => '/', '1' => '/drop'],
            'item_object_id' => ['0' => '0', '1' => '0'],
            'item_remove' => ['1' => '1'],
            'add_page' => [(string) $pageId],
            'add_post' => [(string) $postId],
            'add_category' => ['3'],
            'add_forum' => ['7'],
            'new_item_title' => 'Docs',
            'new_item_url' => 'https://example.test/docs',
            'new_item_type' => 'custom',
        ]);

        // Existing (minus removed) + page + post + category + forum + custom link.
        $this->assertCount(6, $raw);
        $this->assertSame('Home', $raw[0]['title']);
        $this->assertSame('page', $raw[1]['type']);
        $this->assertSame($pageId, $raw[1]['object_id']);
        $this->assertSame('post', $raw[2]['type']);
        $this->assertSame($postId, $raw[2]['object_id']);
        $this->assertSame('category', $raw[3]['type']);
        $this->assertSame(3, $raw[3]['object_id']);
        $this->assertSame('forum', $raw[4]['type']);
        $this->assertSame(7, $raw[4]['object_id']);
        $this->assertSame('Docs', $raw[5]['title']);
        $this->assertSame('https://example.test/docs', $raw[5]['url']);

        $withCustomOnly = AP_Nav_Menu::itemsFromAdminPost([
            'new_item_title' => 'Docs',
            'new_item_url' => 'https://example.test/docs',
        ]);
        $this->assertCount(1, $withCustomOnly);
        $this->assertSame('Docs', $withCustomOnly[0]['title']);
        $this->assertSame('https://example.test/docs', $withCustomOnly[0]['url']);
    }

    public function testMoveAndRemoveItem(): void
    {
        $this->assertTrue(AP_Nav_Menu::saveMenu('ordered', 'Ordered', [
            ['type' => 'custom', 'title' => 'A', 'url' => '/a'],
            ['type' => 'custom', 'title' => 'B', 'url' => '/b'],
            ['type' => 'custom', 'title' => 'C', 'url' => '/c'],
        ], $this->db));

        $this->assertTrue(AP_Nav_Menu::moveItem('ordered', 2, 'up', $this->db));
        $menu = AP_Nav_Menu::getMenu('ordered', $this->db);
        $this->assertNotNull($menu);
        $this->assertSame(['A', 'C', 'B'], array_column($menu['items'], 'title'));

        $this->assertTrue(AP_Nav_Menu::moveItem('ordered', 0, 'down', $this->db));
        $menu = AP_Nav_Menu::getMenu('ordered', $this->db);
        $this->assertNotNull($menu);
        $this->assertSame(['C', 'A', 'B'], array_column($menu['items'], 'title'));

        // Boundary: cannot move first item up.
        $this->assertFalse(AP_Nav_Menu::moveItem('ordered', 0, 'up', $this->db));

        $this->assertTrue(AP_Nav_Menu::removeItem('ordered', 1, $this->db));
        $menu = AP_Nav_Menu::getMenu('ordered', $this->db);
        $this->assertNotNull($menu);
        $this->assertSame(['C', 'B'], array_column($menu['items'], 'title'));
    }

    public function testForumItemTypeIsStoredAndResolved(): void
    {
        $this->assertTrue(AP_Nav_Menu::saveMenu('fmenu', 'Forums Menu', [
            ['type' => 'forum', 'title' => '', 'object_id' => 42],
            ['type' => 'custom', 'title' => 'Home', 'url' => '/'],
        ], $this->db));

        $menu = AP_Nav_Menu::getMenu('fmenu', $this->db);
        $this->assertNotNull($menu);
        $this->assertCount(2, $menu['items']);
        $this->assertSame('forum', $menu['items'][0]['type']);
        $this->assertSame(42, $menu['items'][0]['object_id']);
        // Without AP_Forum loaded / row missing, title falls back gracefully.
        $title = AP_Nav_Menu::itemTitle($menu['items'][0], $this->db);
        $this->assertNotSame('', $title);
    }

    public function testSaveMenuRoundTripFromAdminPost(): void
    {
        $pageId = AP_Post::insert([
            'post_title' => 'About',
            'post_content' => 'About',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'about-rt',
        ], $this->db);

        AP_Nav_Menu::saveMenu('main', 'Main', [], $this->db);

        $items = AP_Nav_Menu::itemsFromAdminPost([
            'add_page' => [(string) $pageId],
            'new_item_title' => 'Blog',
            'new_item_url' => '/blog',
        ]);
        $this->assertTrue(AP_Nav_Menu::saveMenu('main', 'Main Nav', $items, $this->db));

        $menu = AP_Nav_Menu::getMenu('main', $this->db);
        $this->assertNotNull($menu);
        $this->assertSame('Main Nav', $menu['name']);
        $this->assertCount(2, $menu['items']);
        $this->assertSame('page', $menu['items'][0]['type']);
        $this->assertSame($pageId, $menu['items'][0]['object_id']);
        $this->assertSame('About', AP_Nav_Menu::itemTitle($menu['items'][0], $this->db));
        $this->assertSame('Blog', $menu['items'][1]['title']);
        $this->assertSame('/blog', $menu['items'][1]['url']);
    }

    public function testAllowedItemTypesIncludesForum(): void
    {
        $types = AP_Nav_Menu::allowedItemTypes();
        $this->assertContains('custom', $types);
        $this->assertContains('page', $types);
        $this->assertContains('post', $types);
        $this->assertContains('category', $types);
        $this->assertContains('forum', $types);
    }

    public function testLocationsFromAdminPostBuildsFullMap(): void
    {
        $registered = [
            'primary' => 'Primary',
            'footer' => 'Footer',
            'social' => 'Social',
        ];
        $map = AP_Nav_Menu::locationsFromAdminPost([
            'menu_location' => [
                'primary' => 'main',
                'footer' => '',
                'social' => 'links',
                'unknown' => 'ignored',
            ],
        ], $registered);

        $this->assertSame(['primary' => 'main', 'social' => 'links'], $map);
        // Empty footer is omitted (unassigned).
        $this->assertArrayNotHasKey('footer', $map);
        // Unregistered keys never appear.
        $this->assertArrayNotHasKey('unknown', $map);
    }

    public function testMergeMenuLocationCheckboxesAssignsAndClears(): void
    {
        $registered = [
            'primary' => 'Primary',
            'footer' => 'Footer',
        ];
        $current = [
            'primary' => 'other',
            'footer' => 'main',
        ];

        // Editing "main": check primary (steals from other), uncheck footer.
        $merged = AP_Nav_Menu::mergeMenuLocationCheckboxes(
            $current,
            $registered,
            'main',
            ['location_primary' => '1']
        );
        $this->assertSame('main', $merged['primary']);
        $this->assertArrayNotHasKey('footer', $merged);

        // Editing "other": leave primary unchecked → clears its assignment.
        $cleared = AP_Nav_Menu::mergeMenuLocationCheckboxes(
            ['primary' => 'other'],
            $registered,
            'other',
            []
        );
        $this->assertSame([], $cleared);
    }

    public function testGetLocationsForMenu(): void
    {
        AP_Nav_Menu::registerLocations([
            'primary' => 'Primary',
            'footer' => 'Footer',
        ]);
        AP_Nav_Menu::saveMenu('main', 'Main', [
            ['type' => 'custom', 'title' => 'Home', 'url' => '/'],
        ], $this->db);
        AP_Nav_Menu::saveMenu('foot', 'Foot', [
            ['type' => 'custom', 'title' => 'About', 'url' => '/about'],
        ], $this->db);
        AP_Nav_Menu::setLocationAssignments([
            'primary' => 'main',
            'footer' => 'foot',
        ], $this->db);

        $this->assertSame(['primary'], AP_Nav_Menu::getLocationsForMenu('main', $this->db));
        $this->assertSame(['footer'], AP_Nav_Menu::getLocationsForMenu('foot', $this->db));
        $this->assertSame([], AP_Nav_Menu::getLocationsForMenu('missing', $this->db));
    }

    public function testPrimaryAndFooterLocationsFullyControllable(): void
    {
        AP_Nav_Menu::registerLocations([
            'primary' => 'Primary',
            'footer' => 'Footer',
        ]);

        AP_Nav_Menu::saveMenu('header-nav', 'Header Nav', [
            ['type' => 'custom', 'title' => 'Welcome', 'url' => '/welcome'],
            ['type' => 'custom', 'title' => 'Docs', 'url' => '/docs'],
        ], $this->db);
        AP_Nav_Menu::saveMenu('footer-nav', 'Footer Nav', [
            ['type' => 'custom', 'title' => 'Privacy', 'url' => '/privacy'],
        ], $this->db);

        // Manage Locations form: assign both locations.
        $map = AP_Nav_Menu::locationsFromAdminPost([
            'menu_location' => [
                'primary' => 'header-nav',
                'footer' => 'footer-nav',
            ],
        ], AP_Nav_Menu::getRegisteredLocations());
        $this->assertTrue(AP_Nav_Menu::setLocationAssignments($map, $this->db));

        $this->assertTrue(AP_Nav_Menu::hasNavMenu('primary', $this->db));
        $this->assertTrue(AP_Nav_Menu::hasNavMenu('footer', $this->db));

        $primaryHtml = AP_Nav_Menu::render([
            'theme_location' => 'primary',
            'echo' => false,
            'container_class' => 'ap-nav ap-nav--primary',
        ], $this->db);
        $this->assertStringContainsString('Welcome', $primaryHtml);
        $this->assertStringContainsString('Docs', $primaryHtml);
        $this->assertStringContainsString('ap-nav--primary', $primaryHtml);
        $this->assertStringNotContainsString('Privacy', $primaryHtml);

        $footerHtml = AP_Nav_Menu::render([
            'theme_location' => 'footer',
            'echo' => false,
            'container_class' => 'ap-nav ap-nav--footer',
        ], $this->db);
        $this->assertStringContainsString('Privacy', $footerHtml);
        $this->assertStringNotContainsString('Welcome', $footerHtml);

        // Reassign primary to a different menu; footer stays.
        $map2 = AP_Nav_Menu::locationsFromAdminPost([
            'menu_location' => [
                'primary' => 'footer-nav',
                'footer' => 'footer-nav',
            ],
        ], AP_Nav_Menu::getRegisteredLocations());
        AP_Nav_Menu::setLocationAssignments($map2, $this->db);

        $primaryHtml2 = AP_Nav_Menu::render([
            'theme_location' => 'primary',
            'echo' => false,
        ], $this->db);
        $this->assertStringContainsString('Privacy', $primaryHtml2);
        $this->assertStringNotContainsString('Welcome', $primaryHtml2);

        // Clear primary via empty selection.
        $map3 = AP_Nav_Menu::locationsFromAdminPost([
            'menu_location' => [
                'primary' => '',
                'footer' => 'footer-nav',
            ],
        ], AP_Nav_Menu::getRegisteredLocations());
        AP_Nav_Menu::setLocationAssignments($map3, $this->db);
        $this->assertFalse(AP_Nav_Menu::hasNavMenu('primary', $this->db));
        $this->assertTrue(AP_Nav_Menu::hasNavMenu('footer', $this->db));
        $this->assertSame('', AP_Nav_Menu::getMenuSlugForLocation('primary', $this->db));
        $this->assertSame('footer-nav', AP_Nav_Menu::getMenuSlugForLocation('footer', $this->db));
    }

    public function testExtraRegisteredLocationIsControllable(): void
    {
        AP_Nav_Menu::registerLocations([
            'primary' => 'Primary',
            'footer' => 'Footer',
            'sidebar' => 'Sidebar Menu',
        ]);
        AP_Nav_Menu::saveMenu('side', 'Sidebar', [
            ['type' => 'custom', 'title' => 'Widget Link', 'url' => '/w'],
        ], $this->db);

        $map = AP_Nav_Menu::locationsFromAdminPost([
            'menu_location' => [
                'primary' => '',
                'footer' => '',
                'sidebar' => 'side',
            ],
        ], AP_Nav_Menu::getRegisteredLocations());
        $this->assertTrue(AP_Nav_Menu::setLocationAssignments($map, $this->db));
        $this->assertSame('side', AP_Nav_Menu::getMenuSlugForLocation('sidebar', $this->db));

        $html = AP_Nav_Menu::render([
            'theme_location' => 'sidebar',
            'echo' => false,
        ], $this->db);
        $this->assertStringContainsString('Widget Link', $html);
    }
}
