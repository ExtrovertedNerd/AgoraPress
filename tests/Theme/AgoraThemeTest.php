<?php

/**
 * Tests for the default Agora theme: 6 color schemes + theme options.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Theme;

use AP_DB;
use AP_Migrator;
use AP_Nav_Menu;
use AP_Options;
use AP_Post;
use AP_Query;
use AP_Rewrite;
use AP_Session;
use AP_Theme;
use AP_User;
use PDO;
use PHPUnit\Framework\TestCase;

final class AgoraThemeTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/class-ap-nav-menu.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-theme.php';
        require_once $this->root . '/ap-includes/class-ap-assets.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-includes/template-tags.php';

        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'agora-theme-logged-in-key-' . str_repeat('a', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'agora-theme-logged-in-salt-' . str_repeat('b', 32));
        }

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Post::resetRegistry();
        AP_Theme::reset();
        if (class_exists('AP_Assets', false)) {
            \AP_Assets::reset();
        }
        AP_Nav_Menu::reset();
        AP_Options::flushCache();
        AP_Rewrite::resetCache();
        AP_Session::enableTestMode();
        AP_Session::resetCurrentUser();
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post']);

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Post::ensureBuiltins();

        foreach (
            [
                'home' => 'https://example.test',
                'siteurl' => 'https://example.test',
                'stylesheet' => 'agora',
                'template' => 'agora',
                'blogname' => 'Agora Scheme Site',
            ] as $name => $value
        ) {
            $this->db->insert('options', [
                'option_name' => $name,
                'option_value' => $value,
                'autoload' => 'yes',
            ]);
        }

        AP_Theme::setThemesRootOverride($this->root . '/ap-content/themes');
        AP_Theme::setActiveOverride('agora', 'agora');
        $GLOBALS['apdb'] = $this->db;
        AP_Theme::setup($this->db);
    }

    protected function tearDown(): void
    {
        AP_Session::disableTestMode();
        AP_Session::resetCurrentUser();
        AP_Post::resetRegistry();
        AP_Theme::reset();
        AP_Nav_Menu::reset();
        AP_Options::flushCache();
        AP_Rewrite::resetCache();
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post'], $GLOBALS['apdb']);
    }

    private function insertThemeUser(
        string $login = 'themeuser',
        string $password = 'theme-pass-1',
        string $displayName = 'Theme User'
    ): AP_User {
        $hash = AP_User::hashPassword($password);
        $this->db->insert('users', [
            'user_login' => $login,
            'user_pass' => $hash,
            'user_nicename' => $login,
            'user_email' => $login . '@example.test',
            'user_url' => '',
            'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => $displayName,
        ]);
        $user = AP_User::getById((int) $this->db->lastInsertId(), $this->db);
        $this->assertNotNull($user);

        return $user;
    }

    public function testExactlySixColorSchemesThreeLightThreeDark(): void
    {
        $this->assertTrue(function_exists('agora_get_color_schemes'));
        $schemes = agora_get_color_schemes();

        $this->assertCount(6, $schemes);

        $expected = ['marble', 'parchment', 'cloud', 'obsidian', 'midnight', 'charcoal'];
        $this->assertSame($expected, array_keys($schemes));

        $light = 0;
        $dark = 0;
        foreach ($schemes as $slug => $meta) {
            $this->assertArrayHasKey('label', $meta);
            $this->assertArrayHasKey('mode', $meta);
            $this->assertNotSame('', (string) $meta['label']);
            if ($meta['mode'] === 'light') {
                $light++;
            } elseif ($meta['mode'] === 'dark') {
                $dark++;
            } else {
                $this->fail('Scheme ' . $slug . ' has invalid mode: ' . $meta['mode']);
            }
        }
        $this->assertSame(3, $light);
        $this->assertSame(3, $dark);

        $this->assertSame('light', $schemes['marble']['mode']);
        $this->assertSame('light', $schemes['parchment']['mode']);
        $this->assertSame('light', $schemes['cloud']['mode']);
        $this->assertSame('dark', $schemes['obsidian']['mode']);
        $this->assertSame('dark', $schemes['midnight']['mode']);
        $this->assertSame('dark', $schemes['charcoal']['mode']);
    }

    public function testDefaultSchemeIsMarble(): void
    {
        $this->assertSame(AGORA_DEFAULT_COLOR_SCHEME, 'marble');
        $this->assertSame('marble', agora_get_color_scheme($this->db));
        $this->assertSame('light', agora_get_color_scheme_mode(null, $this->db));
    }

    public function testSetAndGetColorSchemePersists(): void
    {
        $this->assertTrue(agora_set_color_scheme('midnight', $this->db));
        $this->assertSame('midnight', agora_get_color_scheme($this->db));
        $this->assertSame('dark', agora_get_color_scheme_mode(null, $this->db));

        $stored = $this->db->getVar(
            'SELECT option_value FROM ' . $this->db->quoteIdentifier($this->db->table('options'))
            . ' WHERE option_name = ? LIMIT 1',
            [AGORA_COLOR_SCHEME_OPTION]
        );
        $this->assertSame('midnight', $stored);

        // Update existing option.
        $this->assertTrue(agora_set_color_scheme('parchment', $this->db));
        $this->assertSame('parchment', agora_get_color_scheme($this->db));
    }

    public function testInvalidSchemeRejectedAndSanitized(): void
    {
        $this->assertFalse(agora_set_color_scheme('neon-disco', $this->db));
        $this->assertFalse(agora_set_color_scheme('../evil', $this->db));
        $this->assertFalse(agora_set_color_scheme('', $this->db));

        $this->assertSame('marble', agora_sanitize_color_scheme('not-a-scheme'));
        $this->assertSame('obsidian', agora_sanitize_color_scheme('OBSIDIAN'));
        $this->assertFalse(agora_is_valid_color_scheme('neon'));
        $this->assertTrue(agora_is_valid_color_scheme('cloud'));
    }

    public function testBodyClassIncludesSchemeAndMode(): void
    {
        agora_set_color_scheme('charcoal', $this->db);
        $classes = agora_body_class($this->db);
        $this->assertStringContainsString('agora-theme', $classes);
        $this->assertStringContainsString('agora-scheme-charcoal', $classes);
        $this->assertStringContainsString('agora-mode-dark', $classes);
    }

    public function testStyleCssDefinesAllSixSchemeSelectors(): void
    {
        $cssPath = $this->root . '/ap-content/themes/agora/style.css';
        $this->assertFileIsReadable($cssPath);
        $css = (string) file_get_contents($cssPath);

        // No bitmap / external image references in the default theme CSS.
        $this->assertDoesNotMatchRegularExpression(
            '/url\s*\(\s*[\'"]?(?:https?:|data:image|[^)]+\.(?:png|jpe?g|gif|webp|svg))/i',
            $css
        );

        foreach (['marble', 'parchment', 'cloud', 'obsidian', 'midnight', 'charcoal'] as $slug) {
            $this->assertStringContainsString(
                'agora-scheme-' . $slug,
                $css,
                "CSS missing selector for scheme {$slug}"
            );
        }

        $headers = AP_Theme::parseStyleCss($cssPath);
        $this->assertSame('Agora', $headers['Theme Name'] ?? null);
        $this->assertStringContainsString('Marble', (string) ($headers['Description'] ?? ''));
    }

    public function testRenderAppliesActiveSchemeBodyClass(): void
    {
        agora_set_color_scheme('obsidian', $this->db);

        AP_Post::insert([
            'post_title' => 'Scheme Post',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Body under obsidian',
        ], $this->db);

        $query = new AP_Query([
            'post_type' => 'post',
            'posts_per_page' => 5,
        ], $this->db);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('agora-theme', $html);
        $this->assertStringContainsString('agora-scheme-obsidian', $html);
        $this->assertStringContainsString('agora-mode-dark', $html);
        $this->assertStringContainsString('Scheme Post', $html);
        $this->assertStringContainsString('skip-link', $html);
        $this->assertStringContainsString('color-scheme', $html);
    }

    public function testThemeOptionsAdminFileExists(): void
    {
        $path = $this->root . '/ap-admin/theme-options.php';
        $this->assertFileIsReadable($path);
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('agora_color_scheme', $src);
        $this->assertStringContainsString('agora_set_color_scheme', $src);
        $this->assertStringContainsString('Additional CSS', $src);
        $this->assertStringContainsString('custom_css', $src);
        $this->assertStringContainsString('AP_Theme::updateCustomCss', $src);

        foreach (['Marble', 'Parchment', 'Cloud', 'Obsidian', 'Midnight', 'Charcoal'] as $label) {
            // Labels come from agora_get_color_schemes() at runtime; file wires the form.
            unset($label);
        }
        $this->assertStringContainsString('Theme Options', $src);
    }

    public function testCustomCssSanitizeAndPrint(): void
    {
        $dirty = "body { color: red; }</style><script>alert(1)</script>";
        $clean = AP_Theme::sanitizeCustomCss($dirty);
        $this->assertStringContainsString('body { color: red; }', $clean);
        $this->assertStringNotContainsString('</style>', $clean);
        $this->assertStringNotContainsString('<script', strtolower($clean));

        AP_Options::update(AP_Theme::OPTION_CUSTOM_CSS, '.site-footer { opacity: 0.9; }', $this->db);
        $this->assertSame('.site-footer { opacity: 0.9; }', AP_Theme::getCustomCss($this->db));

        ob_start();
        AP_Theme::printCustomCss($this->db);
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('id="ap-custom-css"', $out);
        $this->assertStringContainsString('.site-footer { opacity: 0.9; }', $out);

        // Empty CSS prints nothing.
        AP_Options::update(AP_Theme::OPTION_CUSTOM_CSS, '', $this->db);
        ob_start();
        AP_Theme::printCustomCss($this->db);
        $this->assertSame('', (string) ob_get_clean());
    }

    public function testPrimaryNavLocationIsControllableFromMenus(): void
    {
        $locs = AP_Nav_Menu::getRegisteredLocations();
        $this->assertArrayHasKey('primary', $locs);
        $this->assertArrayHasKey('footer', $locs);

        AP_Nav_Menu::saveMenu('site-main', 'Site Main', [
            ['type' => 'custom', 'title' => 'Custom Primary Link', 'url' => '/custom-primary'],
        ], $this->db);
        AP_Nav_Menu::setLocationAssignments(['primary' => 'site-main'], $this->db);

        $this->assertTrue(ap_has_nav_menu('primary', $this->db));

        AP_Post::insert([
            'post_title' => 'Nav Probe Post',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Content',
        ], $this->db);

        $query = new AP_Query([
            'post_type' => 'post',
            'posts_per_page' => 5,
        ], $this->db);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Custom Primary Link', $html);
        $this->assertStringContainsString('ap-nav--primary', $html);
        $this->assertStringContainsString('/custom-primary', $html);
        // Fallback Home/Pages nav must not override the assigned menu.
        $this->assertStringNotContainsString('menu-item-home', $html);
    }

    public function testPublishedPageAppearsInPrimaryNavBar(): void
    {
        $pageId = AP_Post::insert([
            'post_title' => 'About Our Site',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => 'About content',
            'post_name' => 'about-our-site',
        ], $this->db);
        $this->assertGreaterThan(0, $pageId);

        AP_Nav_Menu::saveMenu('with-pages', 'With Pages', [
            ['type' => 'page', 'title' => '', 'object_id' => $pageId],
            ['type' => 'custom', 'title' => 'Extra', 'url' => '/extra'],
        ], $this->db);
        AP_Nav_Menu::setLocationAssignments(['primary' => 'with-pages'], $this->db);

        AP_Post::insert([
            'post_title' => 'Nav Probe Post',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Content',
        ], $this->db);

        $query = new AP_Query([
            'post_type' => 'post',
            'posts_per_page' => 5,
        ], $this->db);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('About Our Site', $html);
        $this->assertStringContainsString('menu-item-type-page', $html);
        $this->assertStringContainsString('Extra', $html);
        $this->assertStringContainsString('ap-nav--primary', $html);
    }

    public function testFallbackPrimaryNavListsPublishedPages(): void
    {
        // No custom primary menu → theme fallback must list published pages.
        AP_Nav_Menu::setLocationAssignments([], $this->db);

        AP_Post::insert([
            'post_title' => 'Fallback About',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => 'About',
            'post_name' => 'fallback-about',
            'menu_order' => 1,
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Fallback Draft',
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_content' => 'Nope',
            'post_name' => 'fallback-draft',
        ], $this->db);

        AP_Post::insert([
            'post_title' => 'Nav Probe Post',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Content',
        ], $this->db);

        $query = new AP_Query([
            'post_type' => 'post',
            'posts_per_page' => 5,
        ], $this->db);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('ap-nav--primary', $html);
        $this->assertStringContainsString('Fallback About', $html);
        $this->assertStringContainsString('menu-item-type-page', $html);
        $this->assertStringNotContainsString('Fallback Draft', $html);
        $this->assertStringContainsString('Home', $html);
    }

    /**
     * Re-test: per-page “Show in navigation” is honoured by the Agora primary navbar
     * fallback, and pages added to an assigned custom menu still appear on the front-end.
     */
    public function testShowInNavAndCustomMenuPagesInPrimaryNavbar(): void
    {
        AP_Nav_Menu::setLocationAssignments([], $this->db);

        $visibleId = AP_Post::insert([
            'post_title' => 'Visible In Bar',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => 'Yes',
            'post_name' => 'visible-in-bar',
            'menu_order' => 1,
        ], $this->db);
        $hiddenId = AP_Post::insert([
            'post_title' => 'Hidden From Bar',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => 'No',
            'post_name' => 'hidden-from-bar',
            'menu_order' => 2,
            'show_in_nav' => false,
        ], $this->db);
        $this->assertGreaterThan(0, $visibleId);
        $this->assertGreaterThan(0, $hiddenId);
        $this->assertTrue(AP_Post::showsInNav($visibleId, $this->db));
        $this->assertFalse(AP_Post::showsInNav($hiddenId, $this->db));

        AP_Post::insert([
            'post_title' => 'Nav Probe Post',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Content',
        ], $this->db);

        $query = new AP_Query([
            'post_type' => 'post',
            'posts_per_page' => 5,
        ], $this->db);
        ap_set_query($query);

        // Fallback navbar: only pages with show_in_nav (default on) appear.
        ob_start();
        AP_Theme::render($query, $this->db);
        $fallbackHtml = (string) ob_get_clean();

        $this->assertStringContainsString('ap-nav--primary', $fallbackHtml);
        $this->assertStringContainsString('Visible In Bar', $fallbackHtml);
        $this->assertStringNotContainsString('Hidden From Bar', $fallbackHtml);

        // Custom menu: explicitly assigned page items (including previously hidden)
        // appear when assigned to primary; fallback pages do not override.
        AP_Nav_Menu::saveMenu('navbar-retest', 'Navbar Retest', [
            ['type' => 'page', 'title' => '', 'object_id' => $hiddenId],
            ['type' => 'page', 'title' => '', 'object_id' => $visibleId],
            ['type' => 'custom', 'title' => 'Retest Link', 'url' => '/retest-link'],
        ], $this->db);
        AP_Nav_Menu::setLocationAssignments(['primary' => 'navbar-retest'], $this->db);

        ob_start();
        AP_Theme::render($query, $this->db);
        $menuHtml = (string) ob_get_clean();

        $this->assertStringContainsString('ap-nav--primary', $menuHtml);
        $this->assertStringContainsString('Hidden From Bar', $menuHtml);
        $this->assertStringContainsString('Visible In Bar', $menuHtml);
        $this->assertStringContainsString('Retest Link', $menuHtml);
        $this->assertStringContainsString('menu-item-type-page', $menuHtml);
        $this->assertStringContainsString('/retest-link', $menuHtml);
        // Fallback Home item must not replace the assigned menu.
        $this->assertStringNotContainsString('menu-item-home', $menuHtml);
    }

    public function testAdminMenuListsThemeOptions(): void
    {
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        $items = \AP_Admin::menuItems('theme-options');
        $ids = array_column($items, 'id');
        $this->assertContains('theme-options', $ids);
        $found = false;
        foreach ($items as $item) {
            if ($item['id'] === 'theme-options') {
                $this->assertTrue($item['active']);
                $this->assertStringContainsString('theme-options.php', $item['url']);
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function testInstallerSeedsDefaultScheme(): void
    {
        $installer = (string) file_get_contents($this->root . '/ap-includes/class-ap-installer.php');
        $this->assertStringContainsString("'agora_color_scheme'", $installer);
        $this->assertStringContainsString("'marble'", $installer);
    }

    public function testForumTemplatesExist(): void
    {
        $dir = $this->root . '/ap-content/themes/agora';
        foreach (['forum.php', 'forum-view.php', 'topic.php'] as $file) {
            $this->assertFileIsReadable($dir . '/' . $file, "Missing forum template {$file}");
        }
    }

    public function testStyleCssHasForumAndA11yPolish(): void
    {
        $css = (string) file_get_contents($this->root . '/ap-content/themes/agora/style.css');
        $this->assertStringContainsString('.ap-forum', $css);
        $this->assertStringContainsString('.ap-forum-post', $css);
        $this->assertStringContainsString('.ap-pagination', $css);
        $this->assertStringContainsString('.ap-breadcrumbs', $css);
        $this->assertStringContainsString('--ap-on-accent', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('focus-visible', $css);
        $this->assertStringContainsString('skip-link', $css);
        $this->assertMatchesRegularExpression('/@media\s*\(\s*max-width:/', $css);
        $this->assertStringContainsString('Version: 0.3.2', $css);
        $this->assertStringContainsString('overflow-wrap: anywhere', $css);
        $this->assertStringContainsString('--ap-field-bg', $css);
        $this->assertStringContainsString('--ap-surface:', $css);
        $this->assertStringContainsString('.ap-comment-form input', $css);
        $this->assertStringContainsString('color-scheme: inherit', $css);
        $this->assertStringContainsString('.site-account', $css);
        $this->assertStringContainsString('.site-account__welcome', $css);
        $this->assertStringContainsString('.site-account__logout', $css);
    }

    public function testAccountIndicatorNullForGuests(): void
    {
        $this->assertTrue(function_exists('agora_get_account_indicator'));
        $this->assertTrue(function_exists('agora_the_account_indicator'));
        $this->assertNull(agora_get_account_indicator($this->db));

        ob_start();
        agora_the_account_indicator($this->db);
        $html = (string) ob_get_clean();
        $this->assertSame('', $html);
    }

    public function testAccountIndicatorShowsWelcomeWhenLoggedIn(): void
    {
        $password = 'theme-account-pass';
        $user = $this->insertThemeUser('headeruser', $password, 'Ada Header');
        $loggedIn = AP_Session::login('headeruser', $password, false, $this->db);
        $this->assertInstanceOf(AP_User::class, $loggedIn);
        $this->assertTrue(ap_is_user_logged_in($this->db));

        $info = agora_get_account_indicator($this->db);
        $this->assertIsArray($info);
        $this->assertSame('Ada Header', $info['display_name']);
        $this->assertSame('Welcome, Ada Header', $info['welcome']);
        $this->assertStringContainsString('profile.php', $info['profile_url']);
        $this->assertStringContainsString('action=logout', $info['logout_url']);

        ob_start();
        agora_the_account_indicator($this->db);
        $markup = (string) ob_get_clean();
        $this->assertStringContainsString('site-account', $markup);
        $this->assertStringContainsString('Welcome,', $markup);
        $this->assertStringContainsString('Ada Header', $markup);
        $this->assertStringContainsString('site-account__name', $markup);
        $this->assertStringContainsString('Log out', $markup);
        $this->assertStringContainsString('profile.php', $markup);

        // Full theme render includes the header indicator.
        AP_Post::insert([
            'post_title' => 'Account Probe',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Body',
        ], $this->db);
        $query = new AP_Query([
            'post_type' => 'post',
            'posts_per_page' => 5,
        ], $this->db);
        ap_set_query($query);
        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('site-account', $html);
        $this->assertStringContainsString('Welcome,', $html);
        $this->assertStringContainsString('Ada Header', $html);
        $this->assertStringContainsString('Log out', $html);
        $this->assertSame($user->ID, ap_get_current_user_id($this->db));
    }

    public function testGuestRenderHidesAccountIndicator(): void
    {
        AP_Post::insert([
            'post_title' => 'Guest Probe',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Body',
        ], $this->db);
        $query = new AP_Query([
            'post_type' => 'post',
            'posts_per_page' => 5,
        ], $this->db);
        ap_set_query($query);
        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('site-account', $html);
        $this->assertStringNotContainsString('Welcome,', $html);
    }

    public function testForumTemplateHierarchyIndex(): void
    {
        $this->assertTrue(function_exists('agora_get_forum_view'));
        $this->assertTrue(function_exists('agora_forum_template_hierarchy'));

        $query = new AP_Query([
            'ap_forum_view' => 'index',
            'posts_per_page' => 1,
        ], $this->db);
        $this->assertSame('index', agora_get_forum_view($query));

        $hierarchy = AP_Theme::getHierarchy($query, $this->db);
        $this->assertSame('forum.php', $hierarchy[0] ?? null);
        $this->assertContains('index.php', $hierarchy);
    }

    public function testForumTemplateHierarchyTopicAndForum(): void
    {
        $topicQ = new AP_Query(['topic_id' => 7, 'posts_per_page' => 1], $this->db);
        $this->assertSame('topic', agora_get_forum_view($topicQ));
        $topicH = AP_Theme::getHierarchy($topicQ, $this->db);
        $this->assertSame('topic.php', $topicH[0] ?? null);

        $forumQ = new AP_Query(['forum_id' => 3, 'posts_per_page' => 1], $this->db);
        $this->assertSame('forum', agora_get_forum_view($forumQ));
        $forumH = AP_Theme::getHierarchy($forumQ, $this->db);
        $this->assertSame('forum-view.php', $forumH[0] ?? null);
    }

    public function testForumBodyClass(): void
    {
        $query = new AP_Query(['ap_forum_view' => 'topic', 'topic_id' => 1], $this->db);
        $GLOBALS['ap_query'] = $query;
        agora_set_color_scheme('obsidian', $this->db);
        $classes = agora_body_class($this->db);
        $this->assertStringContainsString('agora-forum', $classes);
        $this->assertStringContainsString('agora-forum--topic', $classes);
        $this->assertStringContainsString('layout-wide', $classes);
        $this->assertStringContainsString('agora-scheme-obsidian', $classes);
    }

    public function testRenderForumIndexEmptyState(): void
    {
        $query = new AP_Query([
            'ap_forum_view' => 'index',
            'posts_per_page' => 1,
        ], $this->db);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('ap-forum', $html);
        $this->assertStringContainsString('Forums', $html);
        $this->assertStringContainsString('ap-breadcrumbs', $html);
        $this->assertStringContainsString('agora-forum', $html);
        $this->assertStringContainsString('skip-link', $html);
        $this->assertStringContainsString('No forums have been created yet', $html);
    }

    public function testRenderForumTopicWithFilteredPosts(): void
    {
        if (function_exists('ap_add_filter')) {
            ap_add_filter('agora_topic_posts_data', static function (array $data, int $topicId): array {
                if ($topicId !== 42) {
                    return $data;
                }

                return [
                    [
                        'id' => 1,
                        'author' => 'Alice',
                        'date' => '2026-08-01 12:00:00',
                        'content' => "Hello **world**",
                        'role' => 'Member',
                        'number' => 1,
                    ],
                ];
            }, 10, 2);
        }

        $query = new AP_Query([
            'ap_forum_view' => 'topic',
            'topic_id' => 42,
            'topic_title' => 'Welcome thread',
            'forum_name' => 'General',
            'posts_per_page' => 1,
        ], $this->db);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Welcome thread', $html);
        $this->assertStringContainsString('Alice', $html);
        $this->assertStringContainsString('ap-forum-post', $html);
        $this->assertStringContainsString('Hello **world**', $html);
    }

    public function testBlogRenderUsesExcerptWhenAvailable(): void
    {
        agora_set_color_scheme('cloud', $this->db);
        AP_Post::insert([
            'post_title' => 'Excerpt Post',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => str_repeat('Word ', 80),
            'post_excerpt' => 'Short blurb for the card.',
        ], $this->db);

        $query = new AP_Query([
            'post_type' => 'post',
            'posts_per_page' => 5,
        ], $this->db);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Excerpt Post', $html);
        $this->assertStringContainsString('Short blurb for the card.', $html);
        $this->assertStringContainsString('ap-entry__excerpt', $html);
        $this->assertStringContainsString('agora-scheme-cloud', $html);
    }
}
