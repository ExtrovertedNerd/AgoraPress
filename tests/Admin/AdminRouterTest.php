<?php

/**
 * Tests for the registered admin page router (admin.php?page=).
 *
 * Covers URL builder, registry lookup, callback resolution/invoke, and
 * structural gates on the front controller (no path includes from query).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Admin;
use AP_Admin_Menu;
use AP_DB;
use AP_Options;
use AP_Plugin;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Admin::class)]
#[CoversClass(AP_Admin_Menu::class)]
final class AdminRouterTest extends TestCase
{
    private string $root;

    private ?AP_DB $pluginDb = null;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-admin-menu.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        AP_Admin_Menu::reset();
        AP_Admin::clearNotices();
    }

    protected function tearDown(): void
    {
        AP_Admin_Menu::reset();
        AP_Admin::clearNotices();
        unset($_GET['page']);
        if ($this->pluginDb !== null) {
            $this->shutdownPluginSubsystem();
        }
    }

    /**
     * Minimal options + plugin stack so isActive() can be exercised.
     */
    private function bootPluginSubsystem(): void
    {
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-plugin.php';

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $db->query(
            'CREATE TABLE ' . $db->quoteIdentifier($db->table('options')) . ' (
                option_id INTEGER PRIMARY KEY AUTOINCREMENT,
                option_name TEXT NOT NULL UNIQUE,
                option_value TEXT NOT NULL,
                autoload TEXT NOT NULL DEFAULT \'yes\'
            )'
        );
        $db->insert('options', [
            'option_name' => 'active_plugins',
            'option_value' => '[]',
            'autoload' => 'yes',
        ]);

        AP_Options::flushCache();
        AP_Plugin::reset();
        $GLOBALS['apdb'] = $db;
        $this->pluginDb = $db;
    }

    private function pluginDb(): AP_DB
    {
        $this->assertNotNull($this->pluginDb);

        return $this->pluginDb;
    }

    private function shutdownPluginSubsystem(): void
    {
        if (class_exists(AP_Plugin::class, false)) {
            AP_Plugin::reset();
        }
        if (class_exists(AP_Options::class, false)) {
            AP_Options::flushCache();
        }
        unset($GLOBALS['apdb']);
        $this->pluginDb = null;
    }

    public function testAdminPhpFrontControllerExists(): void
    {
        $this->assertFileIsReadable($this->root . '/ap-admin/admin.php');
    }

    public function testAdminPhpIsRegistryOnlyRouter(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/admin.php');

        $this->assertStringContainsString('admin-bootstrap.php', $src);
        $this->assertStringContainsString('resolveRequestedAdminPage', $src);
        $this->assertStringContainsString('requireCapability', $src);
        $this->assertStringContainsString('capabilityForRegisteredPage', $src);
        $this->assertStringContainsString('registeredPageScreenContext', $src);
        $this->assertStringContainsString('notFound', $src);
        $this->assertStringContainsString('unknownAdminPageMessage', $src);
        $this->assertStringContainsString('invokeAdminPageCallback', $src);
        $this->assertStringContainsString('admin-header.php', $src);
        $this->assertStringContainsString('admin-footer.php', $src);
        $this->assertStringContainsString('$ap_admin_screen', $src);
        $this->assertStringContainsString('$ap_admin_title', $src);

        // Never include a path derived from user input.
        $this->assertStringNotContainsString('include $_GET', $src);
        $this->assertStringNotContainsString('require $_GET', $src);
        $this->assertStringNotContainsString('include $page', $src);
        $this->assertStringNotContainsString('require $page', $src);
        $this->assertDoesNotMatchRegularExpression(
            '/\b(include|require)(_once)?\s*\(\s*\$_(GET|REQUEST)/',
            $src
        );
    }

    public function testAdminPhpShellPipelineOrder(): void
    {
        // Login (bootstrap) → resolve registry → notFound if unknown → cap → header → callback → footer.
        // Match executable statements only (docblock also mentions some names).
        $src = (string) file_get_contents($this->root . '/ap-admin/admin.php');
        $bodyStart = strpos($src, "require_once __DIR__ . '/admin-bootstrap.php'");
        $this->assertNotFalse($bodyStart);
        $body = substr($src, $bodyStart);

        $resolve = strpos($body, 'resolveRequestedAdminPage');
        $notFound = strpos($body, 'AP_Admin::notFound');
        $cap = strpos($body, 'AP_Admin::requireCapability');
        $screen = strpos($body, 'registeredPageScreenContext');
        $header = strpos($body, "admin-header.php");
        $invoke = strpos($body, 'invokeAdminPageCallback');
        $footer = strpos($body, "admin-footer.php");

        $this->assertNotFalse($resolve);
        $this->assertNotFalse($notFound);
        $this->assertNotFalse($cap);
        $this->assertNotFalse($screen);
        $this->assertNotFalse($header);
        $this->assertNotFalse($invoke);
        $this->assertNotFalse($footer);

        $this->assertLessThan($notFound, $resolve);
        $this->assertLessThan($cap, $notFound);
        $this->assertLessThan($screen, $cap);
        $this->assertLessThan($header, $screen);
        $this->assertLessThan($invoke, $header);
        $this->assertLessThan($footer, $invoke);
    }

    public function testSanitizePageSlug(): void
    {
        $this->assertSame('logos', AP_Admin::sanitizePageSlug('Logos'));
        $this->assertSame('my-page_1', AP_Admin::sanitizePageSlug(' my-page_1 '));
        $this->assertSame('clean', AP_Admin::sanitizePageSlug('c!l@e#a$n'));
        $this->assertSame('', AP_Admin::sanitizePageSlug(''));
        $this->assertSame('evil', AP_Admin::sanitizePageSlug('../evil'));
        // Dots stripped — never a filesystem path.
        $this->assertSame('evilphp', AP_Admin::sanitizePageSlug('evil.php'));
    }

    public function testRequestPageSlugFromGet(): void
    {
        $_GET['page'] = 'Logos-Settings';
        $this->assertSame('logos-settings', AP_Admin::requestPageSlug());

        $this->assertSame('override', AP_Admin::requestPageSlug('Override'));
        $this->assertSame('', AP_Admin::requestPageSlug(''));
    }

    public function testPageUrlBuildsAdminPhpQuery(): void
    {
        $url = AP_Admin::pageUrl('logos');
        $this->assertStringContainsString('admin.php', $url);
        $this->assertStringContainsString('page=logos', $url);

        $urlExtra = AP_Admin::pageUrl('Logos', ['tab' => 'colors']);
        $this->assertStringContainsString('page=logos', $urlExtra);
        $this->assertStringContainsString('tab=colors', $urlExtra);

        // Malicious slug is sanitized; page key cannot be overwritten by query bag.
        $urlSafe = AP_Admin::pageUrl('../x', ['page' => 'hijack']);
        $this->assertStringContainsString('page=x', $urlSafe);
        $this->assertStringNotContainsString('page=hijack', $urlSafe);
        $this->assertStringNotContainsString('..', $urlSafe);
    }

    public function testGetRegisteredAdminPageAllowlistOnly(): void
    {
        $this->assertNull(AP_Admin::getRegisteredAdminPage('missing'));
        $this->assertNull(AP_Admin::getRegisteredAdminPage(''));
        $this->assertNull(AP_Admin::getRegisteredAdminPage('../plugins/evil.php'));

        $cb = static function (): void {
        };
        AP_Admin_Menu::register([
            'id' => 'logos',
            'title' => 'Logos',
            'capability' => 'manage_options',
            'callback' => $cb,
        ]);

        $page = AP_Admin::getRegisteredAdminPage('logos');
        $this->assertIsArray($page);
        $this->assertSame('logos', $page['id']);
        $this->assertSame('manage_options', $page['capability']);
        $this->assertSame($cb, $page['callback']);

        // Case / sanitize normalized lookup.
        $this->assertIsArray(AP_Admin::getRegisteredAdminPage('LOGOS'));
    }

    public function testResolveAndInvokeAdminPageCallback(): void
    {
        $called = false;
        $cb = static function () use (&$called): void {
            $called = true;
        };

        $resolved = AP_Admin::resolveAdminPageCallback($cb);
        $this->assertIsCallable($resolved);
        $this->assertTrue(AP_Admin::invokeAdminPageCallback($cb));
        $this->assertTrue($called);

        $this->assertNull(AP_Admin::resolveAdminPageCallback(null));
        $this->assertNull(AP_Admin::resolveAdminPageCallback(''));
        $this->assertNull(AP_Admin::resolveAdminPageCallback('this_function_does_not_exist_zz'));
        $this->assertFalse(AP_Admin::invokeAdminPageCallback('this_function_does_not_exist_zz'));
    }

    public function testResolveStringFunctionNameCallback(): void
    {
        // Class::method string form (legacy bare string still accepted when loadable).
        $resolved = AP_Admin::resolveAdminPageCallback(AdminRouterTestCallback::class . '::mark');
        $this->assertIsCallable($resolved);

        AdminRouterTestCallback::$hit = false;
        $this->assertTrue(
            AP_Admin::invokeAdminPageCallback(AdminRouterTestCallback::class . '::mark')
        );
        $this->assertTrue(AdminRouterTestCallback::$hit);

        // Normalized wrapper from registration (string → AP_Admin_String_Callback).
        AP_Admin_Menu::reset();
        $this->assertTrue(AP_Admin_Menu::register([
            'id' => 'wrapped-string-cb',
            'title' => 'Wrapped',
            'callback' => AdminRouterTestCallback::class . '::mark',
        ]));
        $page = AP_Admin_Menu::get('wrapped-string-cb');
        $this->assertIsArray($page);
        $this->assertInstanceOf(\AP_Admin_String_Callback::class, $page['callback']);
        $this->assertSame(
            AdminRouterTestCallback::class . '::mark',
            $page['callback']->target()
        );

        AdminRouterTestCallback::$hit = false;
        $this->assertTrue(AP_Admin::invokeAdminPageCallback($page['callback']));
        $this->assertTrue(AdminRouterTestCallback::$hit);

        // Unresolved wrapper target → soft fail.
        $missing = new \AP_Admin_String_Callback('this_function_does_not_exist_zz');
        $this->assertNull(AP_Admin::resolveAdminPageCallback($missing));
        $this->assertFalse(AP_Admin::invokeAdminPageCallback($missing));
    }

    public function testNotFoundMethodExistsAndUses404(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/includes/class-ap-admin.php');
        $this->assertStringContainsString('function notFound', $src);
        $this->assertStringContainsString('function notFoundHtml', $src);
        $this->assertStringContainsString('function resolveRequestedAdminPage', $src);
        $this->assertStringContainsString('function unknownAdminPageMessage', $src);
        $this->assertStringContainsString('http_response_code(404)', $src);
        $this->assertStringContainsString('function pageUrl', $src);
        $this->assertStringContainsString('function requestPageSlug', $src);
        $this->assertStringContainsString('function getRegisteredAdminPage', $src);
        $this->assertStringContainsString('function resolveAdminPageCallback', $src);
        $this->assertStringContainsString('function invokeAdminPageCallback', $src);
        $this->assertStringContainsString('function capabilityForRegisteredPage', $src);
        $this->assertStringContainsString('function registeredPageScreenContext', $src);
        $this->assertStringContainsString('admin.php → capability from AP_Admin_Menu', $src);
    }

    public function testResolveRequestedAdminPageUnknownIsNull(): void
    {
        // Empty / missing query.
        unset($_GET['page']);
        $this->assertNull(AP_Admin::resolveRequestedAdminPage());
        $this->assertNull(AP_Admin::resolveRequestedAdminPage(''));
        $this->assertNull(AP_Admin::resolveRequestedAdminPage('   '));

        // Unregistered slug.
        $_GET['page'] = 'not-registered-zzz';
        $this->assertNull(AP_Admin::resolveRequestedAdminPage());
        $this->assertNull(AP_Admin::resolveRequestedAdminPage('not-registered-zzz'));

        // Path-like / traversal / plugin file attempts never resolve to a page.
        $malicious = [
            '../evil',
            '../../ap-content/plugins/evil/admin.php',
            'ap-content/plugins/x.php',
            "evil\0.php",
            'plugins/foo/settings.php',
            '/etc/passwd',
            '....//....//evil',
        ];
        foreach ($malicious as $raw) {
            $this->assertNull(
                AP_Admin::resolveRequestedAdminPage($raw),
                'Expected null for path-like page slug: ' . $raw
            );
            $this->assertNull(AP_Admin::getRegisteredAdminPage(AP_Admin::sanitizePageSlug($raw)));
        }

        // Registered page resolves; still allowlist-only (never a filesystem path).
        AP_Admin_Menu::register([
            'id' => 'logos',
            'title' => 'Logos',
            'capability' => 'manage_options',
            'callback' => static function (): void {
            },
        ]);
        $this->assertIsArray(AP_Admin::resolveRequestedAdminPage('logos'));
        $this->assertIsArray(AP_Admin::resolveRequestedAdminPage('LOGOS'));
        // Path decorations are stripped to a slug, then allowlisted — not included as a path.
        $this->assertSame('logos', AP_Admin::sanitizePageSlug('../logos'));
        $resolvedFromDecorated = AP_Admin::resolveRequestedAdminPage('../logos');
        $this->assertIsArray($resolvedFromDecorated);
        $this->assertSame('logos', $resolvedFromDecorated['id']);
        // Extension / junk characters do not invent a different include target.
        $this->assertSame('logosphp', AP_Admin::sanitizePageSlug('logos.php'));
        $this->assertNull(AP_Admin::resolveRequestedAdminPage('logos.php'));
    }

    public function testUnknownAdminPageMessageIsStaticAndSafe(): void
    {
        $msg = AP_Admin::unknownAdminPageMessage();
        $this->assertNotSame('', trim($msg));
        // Must not be a template that interpolates query input.
        $this->assertStringNotContainsString('%s', $msg);
        $this->assertStringNotContainsString('{', $msg);
        $this->assertStringNotContainsString('$_GET', $msg);

        $router = (string) file_get_contents($this->root . '/ap-admin/admin.php');
        $this->assertStringContainsString('unknownAdminPageMessage', $router);
        // Router must not pass $_GET['page'] into notFound.
        $this->assertDoesNotMatchRegularExpression(
            '/notFound\s*\(\s*\$_GET/',
            $router
        );
        $this->assertDoesNotMatchRegularExpression(
            '/notFound\s*\(\s*\$pageId/',
            $router
        );
    }

    public function testNotFoundHtmlEscapesMessageAndOmitsRequestInput(): void
    {
        $_GET['page'] = '<script>alert(1)</script>../../evil.php';
        $html = AP_Admin::notFoundHtml(AP_Admin::unknownAdminPageMessage());

        $this->assertStringContainsString('Page not found', $html);
        $this->assertStringContainsString(AP_Admin::unknownAdminPageMessage(), $html);
        $this->assertStringContainsString('Back to dashboard', $html);
        // Static message path: raw query must never appear in the 404 body.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('evil.php', $html);
        $this->assertStringNotContainsString('../../', $html);

        // Escaping when a custom message is supplied (defensive).
        $xss = AP_Admin::notFoundHtml('<img src=x onerror=alert(1)>');
        $this->assertStringNotContainsString('<img src=x', $xss);
        $this->assertStringContainsString('&lt;img', $xss);

        $src = (string) file_get_contents($this->root . '/ap-admin/includes/class-ap-admin.php');
        // notFound() must emit 404 then the HTML helper (no include of user paths).
        // Match the exit helper specifically (not notFoundHtml).
        $notFoundPos = strpos($src, 'function notFound(string');
        $this->assertNotFalse($notFoundPos);
        $chunk = substr($src, $notFoundPos, 600);
        $this->assertStringContainsString('http_response_code(404)', $chunk);
        $this->assertStringContainsString('notFoundHtml', $chunk);
        $this->assertStringContainsString('exit', $chunk);
    }

    public function testUnknownPageDoesNotInvokeCallback(): void
    {
        $called = false;
        AP_Admin_Menu::register([
            'id' => 'real-page',
            'title' => 'Real',
            'callback' => static function () use (&$called): void {
                $called = true;
            },
        ]);

        // Unknown resolves to null — router would notFound before invoke.
        $unknown = AP_Admin::resolveRequestedAdminPage('missing-page');
        $this->assertNull($unknown);
        $this->assertFalse($called);

        // Known page can still be invoked via the registered callback only.
        $known = AP_Admin::resolveRequestedAdminPage('real-page');
        $this->assertIsArray($known);
        $this->assertTrue(AP_Admin::invokeAdminPageCallback($known['callback']));
        $this->assertTrue($called);

        // Extension-shaped slug does not resolve; no accidental callback dispatch.
        $called = false;
        $this->assertNull(AP_Admin::resolveRequestedAdminPage('real-page.php'));
        $this->assertFalse($called);
        // Path decorations strip to the allowlisted id (callback only, never include).
        $decorated = AP_Admin::resolveRequestedAdminPage('../real-page');
        $this->assertIsArray($decorated);
        $this->assertSame('real-page', $decorated['id']);
    }

    public function testAdminPhpUnknownPageGateBeforeChromeAndCallback(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/admin.php');
        $bodyStart = strpos($src, "require_once __DIR__ . '/admin-bootstrap.php'");
        $this->assertNotFalse($bodyStart);
        $body = substr($src, $bodyStart);

        // null page → notFound; nothing after that runs for unknown pages.
        $this->assertMatchesRegularExpression(
            '/resolveRequestedAdminPage\s*\(\s*\)\s*;\s*if\s*\(\s*\$page\s*===\s*null\s*\)\s*\{[^}]*notFound\s*\(\s*AP_Admin::unknownAdminPageMessage\s*\(\s*\)\s*\)/s',
            $body
        );

        $notFound = strpos($body, 'AP_Admin::notFound');
        $header = strpos($body, 'admin-header.php');
        $invoke = strpos($body, 'invokeAdminPageCallback');
        $this->assertNotFalse($notFound);
        $this->assertNotFalse($header);
        $this->assertNotFalse($invoke);
        $this->assertLessThan($header, $notFound);
        $this->assertLessThan($invoke, $notFound);
    }

    public function testScreenCapabilitiesDocumentsAdminRouter(): void
    {
        $map = AP_Admin::screenCapabilities();
        // Dynamic screen — not a fixed cap in the map.
        $this->assertArrayNotHasKey('admin.php', $map);

        $src = (string) file_get_contents($this->root . '/ap-admin/includes/class-ap-admin.php');
        $this->assertStringContainsString('admin.php → capability from AP_Admin_Menu', $src);
        $this->assertStringContainsString('function capabilityForScreen', $src);
        $this->assertStringContainsString('function registeredScreenCapabilities', $src);
        $this->assertStringContainsString('function isMenuItemActive', $src);
        $this->assertStringContainsString('function applyMenuActiveState', $src);
    }

    public function testRegisteredScreenCapabilitiesFromRegistry(): void
    {
        $this->assertSame([], AP_Admin::registeredScreenCapabilities());

        AP_Admin_Menu::register([
            'id' => 'logos',
            'title' => 'Logos',
            'capability' => 'manage_options',
            'callback' => static function (): void {
            },
        ]);
        AP_Admin_Menu::register([
            'id' => 'editor-tools',
            'title' => 'Editor Tools',
            'capability' => 'edit_posts',
            'callback' => static function (): void {
            },
        ]);

        $map = AP_Admin::registeredScreenCapabilities();
        $this->assertSame('manage_options', $map['logos']);
        $this->assertSame('edit_posts', $map['editor-tools']);
        // Keys are menu/screen ids (not admin.php basenames).
        $this->assertArrayNotHasKey('admin.php', $map);
    }

    public function testCapabilityForScreenStaticAndRegistered(): void
    {
        // Static core screens.
        $this->assertSame('manage_options', AP_Admin::capabilityForScreen('options-general.php'));
        $this->assertSame('list_users', AP_Admin::capabilityForScreen('users.php'));
        $this->assertSame('read', AP_Admin::capabilityForScreen('index.php'));
        $this->assertNull(AP_Admin::capabilityForScreen('not-a-real-screen.php'));

        // admin.php without registry entry / empty page → null (deny).
        $this->assertNull(AP_Admin::capabilityForScreen('admin.php'));
        $this->assertNull(AP_Admin::capabilityForScreen('admin.php', 'missing'));
        $this->assertNull(AP_Admin::capabilityForScreen('admin.php', ''));

        AP_Admin_Menu::register([
            'id' => 'logos',
            'title' => 'Logos',
            'capability' => 'manage_options',
            'callback' => static function (): void {
            },
        ]);
        AP_Admin_Menu::register([
            'id' => 'mod-tools',
            'title' => 'Mod Tools',
            'capability' => 'moderate_comments',
            'callback' => static function (): void {
            },
        ]);

        $this->assertSame('manage_options', AP_Admin::capabilityForScreen('admin.php', 'logos'));
        $this->assertSame('moderate_comments', AP_Admin::capabilityForScreen('admin.php', 'mod-tools'));
        // Case / sanitize.
        $this->assertSame('manage_options', AP_Admin::capabilityForScreen('admin.php', 'Logos'));
        // Screen id form (menu highlight id).
        $this->assertSame('manage_options', AP_Admin::capabilityForScreen('logos'));
        $this->assertSame('moderate_comments', AP_Admin::capabilityForScreen('mod-tools'));

        // Request slug from $_GET when pageSlug omitted.
        $_GET['page'] = 'mod-tools';
        $this->assertSame('moderate_comments', AP_Admin::capabilityForScreen('admin.php'));
        unset($_GET['page']);
    }

    public function testIsMenuItemActiveAndApplyMenuActiveState(): void
    {
        $this->assertTrue(AP_Admin::isMenuItemActive('logos', 'logos'));
        $this->assertFalse(AP_Admin::isMenuItemActive('logos', 'plugins'));
        $this->assertFalse(AP_Admin::isMenuItemActive('', 'logos'));
        $this->assertFalse(AP_Admin::isMenuItemActive('logos', ''));
        $this->assertFalse(AP_Admin::isMenuItemActive('Logos', 'logos')); // exact match only

        $items = [
            ['id' => 'dashboard', 'label' => 'Dashboard', 'url' => '/a', 'active' => false, 'cap' => 'read'],
            ['id' => 'logos', 'label' => 'Logos', 'url' => '/b', 'active' => false, 'cap' => 'manage_options'],
            ['id' => 'plugins', 'label' => 'Plugins', 'url' => '/c', 'active' => true, 'cap' => 'activate_plugins'],
        ];
        $marked = AP_Admin::applyMenuActiveState($items, 'logos');
        $this->assertFalse($marked[0]['active']);
        $this->assertTrue($marked[1]['active']);
        $this->assertFalse($marked[2]['active']); // previous true is cleared

        // Core menuItems uses applyMenuActiveState for $current.
        $menu = AP_Admin::menuItems('options-general');
        $found = false;
        foreach ($menu as $item) {
            if ($item['id'] === 'options-general') {
                $this->assertTrue($item['active']);
                $found = true;
            } else {
                $this->assertFalse($item['active'], $item['id']);
            }
        }
        $this->assertTrue($found, 'options-general menu item present');
    }

    public function testMenuActiveStateWiresRegisteredPageScreenId(): void
    {
        AP_Admin_Menu::register([
            'id' => 'logos',
            'parent' => 'settings',
            'title' => 'Logos Settings',
            'menu' => 'Logos',
            'capability' => 'manage_options',
            'callback' => static function (): void {
            },
        ]);
        $page = AP_Admin::getRegisteredAdminPage('logos');
        $this->assertIsArray($page);
        $ctx = AP_Admin::registeredPageScreenContext($page);
        $this->assertSame('logos', $ctx['screen']);

        // Cap map key === screen id used for active state.
        $caps = AP_Admin::registeredScreenCapabilities();
        $this->assertArrayHasKey($ctx['screen'], $caps);
        $this->assertSame(
            AP_Admin::capabilityForRegisteredPage($page),
            $caps[$ctx['screen']]
        );
        $this->assertSame(
            AP_Admin::capabilityForScreen('admin.php', $ctx['screen']),
            $caps[$ctx['screen']]
        );

        // menuItems() merges registry; $current === registry id marks the item active.
        $menu = AP_Admin::menuItems($ctx['screen']);
        $found = false;
        foreach ($menu as $item) {
            if ($item['id'] === 'logos') {
                $this->assertTrue($item['active']);
                $found = true;
            } else {
                $this->assertFalse($item['active'], $item['id']);
            }
        }
        $this->assertTrue($found, 'logos menu item present after registry merge');
        $this->assertTrue(AP_Admin::isMenuItemActive('logos', $ctx['screen']));
    }

    public function testMenuSectionForRegisteredParentMapping(): void
    {
        $this->assertSame('settings', AP_Admin::menuSectionForRegisteredParent('settings'));
        $this->assertSame('settings', AP_Admin::menuSectionForRegisteredParent('Settings'));
        $this->assertSame('plugins', AP_Admin::menuSectionForRegisteredParent('plugins'));
        $this->assertSame('tools', AP_Admin::menuSectionForRegisteredParent('tools'));
        // Empty / unknown → default Plugins section.
        $this->assertSame('plugins', AP_Admin::menuSectionForRegisteredParent(''));
        $this->assertSame('plugins', AP_Admin::menuSectionForRegisteredParent('dashboard'));
    }

    public function testRegisteredMenuItemsFromRegistry(): void
    {
        $this->assertSame([], AP_Admin::registeredMenuItems());

        AP_Admin_Menu::register([
            'id' => 'late-tool',
            'parent' => 'tools',
            'title' => 'Late Tool',
            'menu' => 'Late',
            'capability' => 'manage_options',
            'callback' => static function (): void {
            },
            'position' => 80,
        ]);
        AP_Admin_Menu::register([
            'id' => 'early-tool',
            'parent' => 'tools',
            'title' => 'Early Tool',
            'menu' => 'Early',
            'capability' => 'edit_posts',
            'callback' => static function (): void {
            },
            'position' => 10,
        ]);
        AP_Admin_Menu::register([
            'id' => 'default-parent',
            'parent' => '',
            'title' => 'Default Parent',
            'menu' => 'Default',
            'callback' => static function (): void {
            },
        ]);

        $items = AP_Admin::registeredMenuItems();
        $this->assertCount(3, $items);
        // Sorted by position.
        $this->assertSame('early-tool', $items[0]['id']);
        $this->assertSame('default-parent', $items[1]['id']);
        $this->assertSame('late-tool', $items[2]['id']);

        $this->assertSame('tools', $items[0]['section']);
        $this->assertSame('Early', $items[0]['label']);
        $this->assertSame('edit_posts', $items[0]['cap']);
        $this->assertStringContainsString('admin.php', $items[0]['url']);
        $this->assertStringContainsString('page=early-tool', $items[0]['url']);
        $this->assertFalse($items[0]['active']);

        $this->assertSame('plugins', $items[1]['section']); // empty parent → plugins
        $this->assertSame('tools', $items[2]['section']);
    }

    public function testMenuItemsMergesRegistryByParentSection(): void
    {
        AP_Admin_Menu::register([
            'id' => 'logos',
            'parent' => 'settings',
            'title' => 'Logos Settings',
            'menu' => 'Logos',
            'capability' => 'manage_options',
            'callback' => static function (): void {
            },
            'position' => 50,
        ]);
        AP_Admin_Menu::register([
            'id' => 'plugin-stats',
            'parent' => 'plugins',
            'title' => 'Plugin Stats',
            'menu' => 'Stats',
            'capability' => 'activate_plugins',
            'callback' => static function (): void {
            },
        ]);
        AP_Admin_Menu::register([
            'id' => 'cleanup-tool',
            'parent' => 'tools',
            'title' => 'Cleanup',
            'menu' => 'Cleanup',
            'capability' => 'manage_options',
            'callback' => static function (): void {
            },
        ]);
        AP_Admin_Menu::register([
            'id' => 'orphan-page',
            'parent' => '',
            'title' => 'Orphan',
            'menu' => 'Orphan',
            'callback' => static function (): void {
            },
        ]);

        $menu = AP_Admin::menuItems('');
        $byId = [];
        foreach ($menu as $item) {
            $byId[$item['id']] = $item;
        }

        $this->assertArrayHasKey('logos', $byId);
        $this->assertSame('settings', $byId['logos']['section']);
        $this->assertSame('Logos', $byId['logos']['label']);
        $this->assertSame('manage_options', $byId['logos']['cap']);
        $this->assertStringContainsString('page=logos', $byId['logos']['url']);

        $this->assertArrayHasKey('plugin-stats', $byId);
        $this->assertSame('plugins', $byId['plugin-stats']['section']);

        $this->assertArrayHasKey('cleanup-tool', $byId);
        $this->assertSame('tools', $byId['cleanup-tool']['section']);

        $this->assertArrayHasKey('orphan-page', $byId);
        $this->assertSame('plugins', $byId['orphan-page']['section']);

        // Core items still present.
        $this->assertArrayHasKey('options-general', $byId);
        $this->assertArrayHasKey('plugins', $byId);
        $this->assertArrayHasKey('dashboard', $byId);

        // Registered items sit after last core item of their section (contiguous).
        $ids = array_column($menu, 'id');
        $lastSettingsCore = array_search('options-hall-of-fame', $ids, true);
        $logosIdx = array_search('logos', $ids, true);
        $this->assertNotFalse($lastSettingsCore);
        $this->assertNotFalse($logosIdx);
        $this->assertGreaterThan($lastSettingsCore, $logosIdx);

        $pluginsCore = array_search('plugins', $ids, true);
        $statsIdx = array_search('plugin-stats', $ids, true);
        $orphanIdx = array_search('orphan-page', $ids, true);
        $this->assertNotFalse($pluginsCore);
        $this->assertNotFalse($statsIdx);
        $this->assertNotFalse($orphanIdx);
        $this->assertGreaterThan($pluginsCore, $statsIdx);
        $this->assertGreaterThan($pluginsCore, $orphanIdx);

        $lastToolsCore = array_search('erase-personal-data', $ids, true);
        $cleanupIdx = array_search('cleanup-tool', $ids, true);
        $this->assertNotFalse($lastToolsCore);
        $this->assertNotFalse($cleanupIdx);
        $this->assertGreaterThan($lastToolsCore, $cleanupIdx);
    }

    public function testMenuItemsDoesNotOverrideCoreIdsWithRegistry(): void
    {
        // Attempt to steal a core menu id — core entry wins.
        AP_Admin_Menu::register([
            'id' => 'plugins',
            'parent' => 'settings',
            'title' => 'Hijacked',
            'menu' => 'Hijacked Plugins',
            'callback' => static function (): void {
            },
        ]);

        $menu = AP_Admin::menuItems('');
        $pluginsItems = array_values(array_filter(
            $menu,
            static fn (array $i): bool => $i['id'] === 'plugins'
        ));
        $this->assertCount(1, $pluginsItems);
        $this->assertSame('Installed Plugins', $pluginsItems[0]['label']);
        $this->assertStringContainsString('plugins.php', $pluginsItems[0]['url']);
        $this->assertStringNotContainsString('page=plugins', $pluginsItems[0]['url']);
    }

    public function testMergeRegisteredMenuItemsIsNoopWithoutRegistry(): void
    {
        $core = [
            ['id' => 'dashboard', 'label' => 'Dashboard', 'url' => '/d', 'active' => false, 'cap' => 'read', 'section' => ''],
            ['id' => 'plugins', 'label' => 'Plugins', 'url' => '/p', 'active' => false, 'cap' => 'activate_plugins', 'section' => 'plugins'],
        ];
        $this->assertSame($core, AP_Admin::mergeRegisteredMenuItems($core));
    }

    public function testIsRegisteredPagePluginActiveWithoutPluginKey(): void
    {
        $this->assertTrue(AP_Admin::isRegisteredPagePluginActive([]));
        $this->assertTrue(AP_Admin::isRegisteredPagePluginActive(['plugin' => '']));
        $this->assertTrue(AP_Admin::isRegisteredPagePluginActive(['plugin' => '   ']));
        // No AP_Plugin loaded → linked pages stay visible (early boot / structural tests).
        $this->assertTrue(AP_Admin::isRegisteredPagePluginActive(['plugin' => 'logos/logos.php']));
    }

    public function testRegisteredMenuItemsHidesInactivePluginPages(): void
    {
        $this->bootPluginSubsystem();

        AP_Admin_Menu::register([
            'id' => 'no-plugin-link',
            'parent' => 'settings',
            'title' => 'Core-ish',
            'menu' => 'Core-ish',
            'callback' => static function (): void {
            },
        ]);
        AP_Admin_Menu::register([
            'id' => 'inactive-plugin-page',
            'parent' => 'settings',
            'title' => 'Inactive Plugin',
            'menu' => 'Inactive',
            'plugin' => 'logos/logos.php',
            'callback' => static function (): void {
            },
        ]);
        AP_Admin_Menu::register([
            'id' => 'active-plugin-page',
            'parent' => 'settings',
            'title' => 'Active Plugin',
            'menu' => 'Active',
            'plugin' => 'active-demo/active-demo.php',
            'callback' => static function (): void {
            },
        ]);

        // Neither plugin active yet → only unlinked page appears.
        $items = AP_Admin::registeredMenuItems($this->pluginDb());
        $ids = array_column($items, 'id');
        $this->assertSame(['no-plugin-link'], $ids);
        $this->assertFalse(
            AP_Admin::isRegisteredPagePluginActive(
                ['plugin' => 'logos/logos.php'],
                $this->pluginDb()
            )
        );

        // Activate one plugin → its page appears; the other stays hidden.
        \AP_Options::update('active_plugins', ['active-demo/active-demo.php'], $this->pluginDb());
        $this->assertTrue(
            AP_Admin::isRegisteredPagePluginActive(
                ['plugin' => 'active-demo/active-demo.php'],
                $this->pluginDb()
            )
        );
        $this->assertFalse(
            AP_Admin::isRegisteredPagePluginActive(
                ['plugin' => 'logos/logos.php'],
                $this->pluginDb()
            )
        );

        $items = AP_Admin::registeredMenuItems($this->pluginDb());
        $ids = array_column($items, 'id');
        $this->assertContains('no-plugin-link', $ids);
        $this->assertContains('active-plugin-page', $ids);
        $this->assertNotContains('inactive-plugin-page', $ids);

        // Deactivate → linked page disappears again.
        \AP_Options::update('active_plugins', [], $this->pluginDb());
        $ids = array_column(AP_Admin::registeredMenuItems($this->pluginDb()), 'id');
        $this->assertSame(['no-plugin-link'], $ids);

        $this->shutdownPluginSubsystem();
    }

    public function testMenuItemsHidesInactivePluginLinkedPages(): void
    {
        $this->bootPluginSubsystem();

        AP_Admin_Menu::register([
            'id' => 'linked-settings',
            'parent' => 'settings',
            'title' => 'Linked Settings',
            'menu' => 'Linked',
            'plugin' => 'branding/branding.php',
            'callback' => static function (): void {
            },
        ]);
        AP_Admin_Menu::register([
            'id' => 'always-visible',
            'parent' => 'tools',
            'title' => 'Always',
            'menu' => 'Always',
            'callback' => static function (): void {
            },
        ]);

        $menu = AP_Admin::menuItems('', $this->pluginDb());
        $byId = [];
        foreach ($menu as $item) {
            $byId[$item['id']] = $item;
        }
        $this->assertArrayNotHasKey('linked-settings', $byId);
        $this->assertArrayHasKey('always-visible', $byId);
        $this->assertArrayHasKey('options-general', $byId); // core still present

        \AP_Options::update('active_plugins', ['branding/branding.php'], $this->pluginDb());
        $menu = AP_Admin::menuItems('', $this->pluginDb());
        $byId = [];
        foreach ($menu as $item) {
            $byId[$item['id']] = $item;
        }
        $this->assertArrayHasKey('linked-settings', $byId);
        $this->assertSame('settings', $byId['linked-settings']['section']);
        $this->assertStringContainsString('page=linked-settings', $byId['linked-settings']['url']);
        $this->assertArrayHasKey('always-visible', $byId);

        $this->shutdownPluginSubsystem();
    }

    public function testRegisteredPageCapabilityUsedForGate(): void
    {
        AP_Admin_Menu::register([
            'id' => 'editor-tool',
            'title' => 'Editor Tool',
            'capability' => 'edit_posts',
            'callback' => static function (): void {
            },
        ]);
        AP_Admin_Menu::register([
            'id' => 'default-cap',
            'title' => 'Default Cap',
            'callback' => static function (): void {
            },
        ]);

        $editor = AP_Admin::getRegisteredAdminPage('editor-tool');
        $this->assertIsArray($editor);
        $this->assertSame('edit_posts', $editor['capability']);
        $this->assertSame('edit_posts', AP_Admin::capabilityForRegisteredPage($editor));

        $default = AP_Admin::getRegisteredAdminPage('default-cap');
        $this->assertIsArray($default);
        $this->assertSame(AP_Admin_Menu::DEFAULT_CAPABILITY, $default['capability']);
        $this->assertSame(
            AP_Admin_Menu::DEFAULT_CAPABILITY,
            AP_Admin::capabilityForRegisteredPage($default)
        );

        // Empty / missing capability falls back to manage_options.
        $this->assertSame('manage_options', AP_Admin::capabilityForRegisteredPage([]));
        $this->assertSame(
            'manage_options',
            AP_Admin::capabilityForRegisteredPage(['capability' => '  '])
        );

        // Front controller must gate via capabilityForRegisteredPage (not a fixed string).
        $router = (string) file_get_contents($this->root . '/ap-admin/admin.php');
        $this->assertMatchesRegularExpression(
            '/requireCapability\s*\(\s*AP_Admin::capabilityForRegisteredPage\s*\(\s*\$page\s*\)\s*\)/',
            $router
        );
    }

    public function testRegisteredPageScreenContext(): void
    {
        $ctx = AP_Admin::registeredPageScreenContext([
            'id' => 'logos',
            'title' => 'Logos Settings',
            'menu' => 'Logos',
        ]);
        $this->assertSame('Logos Settings', $ctx['title']);
        $this->assertSame('logos', $ctx['screen']);
        $this->assertStringContainsString('ap-admin-registered-page', $ctx['body_class']);
        $this->assertStringContainsString('ap-admin-page--logos', $ctx['body_class']);

        // Title falls back to menu, then id.
        $fromMenu = AP_Admin::registeredPageScreenContext([
            'id' => 'tool',
            'menu' => 'Tools Label',
        ]);
        $this->assertSame('Tools Label', $fromMenu['title']);
        $this->assertSame('tool', $fromMenu['screen']);

        $fromId = AP_Admin::registeredPageScreenContext(['id' => 'bare-id']);
        $this->assertSame('bare-id', $fromId['title']);
        $this->assertSame('bare-id', $fromId['screen']);

        // Malicious id characters stripped for body class / screen.
        $safe = AP_Admin::registeredPageScreenContext([
            'id' => '../Evil.php',
            'title' => 'Evil',
        ]);
        $this->assertSame('evilphp', $safe['screen']);
        $this->assertStringNotContainsString('..', $safe['body_class']);
        $this->assertStringNotContainsString('.php', $safe['body_class']);
    }

    public function testMenuActiveScreenUsesPageId(): void
    {
        $router = (string) file_get_contents($this->root . '/ap-admin/admin.php');
        $this->assertStringContainsString('registeredPageScreenContext', $router);
        $this->assertStringContainsString("\$ap_admin_screen = \$screen['screen']", $router);
        $this->assertStringContainsString("\$ap_admin_title = \$screen['title']", $router);
        $this->assertStringContainsString("\$ap_admin_body_class = \$screen['body_class']", $router);
    }

    public function testEndToEndRegisterLookupUrlAndInvoke(): void
    {
        $output = '';
        AP_Admin_Menu::register([
            'id' => 'demo',
            'parent' => 'settings',
            'title' => 'Demo Page',
            'menu' => 'Demo',
            'capability' => 'manage_options',
            'callback' => static function () use (&$output): void {
                $output = 'demo-rendered';
            },
            'plugin' => 'demo/demo.php',
            'position' => 20,
        ]);

        $page = AP_Admin::getRegisteredAdminPage('demo');
        $this->assertIsArray($page);
        $this->assertSame('Demo Page', $page['title']);
        $this->assertSame('settings', $page['parent']);

        $url = AP_Admin::pageUrl($page['id']);
        $this->assertStringContainsString('admin.php', $url);
        $this->assertStringContainsString('page=demo', $url);

        // Shell context matches registry (what header would receive).
        $ctx = AP_Admin::registeredPageScreenContext($page);
        $this->assertSame('Demo Page', $ctx['title']);
        $this->assertSame('demo', $ctx['screen']);

        $this->assertSame('manage_options', AP_Admin::capabilityForRegisteredPage($page));
        $this->assertTrue(AP_Admin::invokeAdminPageCallback($page['callback']));
        $this->assertSame('demo-rendered', $output);
    }

    public function testInvalidCallbackDoesNotThrowAndReturnsFalse(): void
    {
        $this->assertFalse(AP_Admin::invokeAdminPageCallback(null));
        $this->assertFalse(AP_Admin::invokeAdminPageCallback(42));
        $this->assertFalse(AP_Admin::invokeAdminPageCallback([]));

        // Router shows a soft error notice when invoke returns false (stays inside chrome).
        $router = (string) file_get_contents($this->root . '/ap-admin/admin.php');
        $this->assertStringContainsString('could not be rendered', $router);
        $this->assertStringContainsString('ap-notice--error', $router);
    }

    public function testPluginSettingsActionLinksEmptyWithoutRegistry(): void
    {
        $this->assertSame([], AP_Admin::pluginSettingsActionLinks(''));
        $this->assertSame([], AP_Admin::pluginSettingsActionLinks('logos/logos.php'));
        $this->assertNull(AP_Admin::pluginSettingsActionLink('logos/logos.php'));
    }

    public function testPluginSettingsActionLinksRequireActivePlugin(): void
    {
        $this->bootPluginSubsystem();

        AP_Admin_Menu::register([
            'id' => 'branding-settings',
            'parent' => 'settings',
            'title' => 'Branding',
            'menu' => 'Branding',
            'plugin' => 'branding/branding.php',
            'callback' => static function (): void {
            },
            'position' => 10,
        ]);
        AP_Admin_Menu::register([
            'id' => 'branding-extra',
            'parent' => 'plugins',
            'title' => 'Extra',
            'menu' => 'Extra Tools',
            'plugin' => 'branding/branding.php',
            'callback' => static function (): void {
            },
            'position' => 5,
        ]);
        AP_Admin_Menu::register([
            'id' => 'other-plugin-page',
            'plugin' => 'other/other.php',
            'callback' => static function (): void {
            },
        ]);

        // Inactive → no Settings links.
        $this->assertSame(
            [],
            AP_Admin::pluginSettingsActionLinks('branding/branding.php', $this->pluginDb())
        );
        $this->assertNull(
            AP_Admin::pluginSettingsActionLink('branding/branding.php', $this->pluginDb())
        );

        \AP_Options::update('active_plugins', ['branding/branding.php'], $this->pluginDb());

        $links = AP_Admin::pluginSettingsActionLinks('branding/branding.php', $this->pluginDb());
        $this->assertCount(2, $links);
        // Sorted by position: extra (5) then branding-settings (10).
        $this->assertSame(['branding-extra', 'branding-settings'], array_column($links, 'id'));
        $this->assertSame('Extra Tools', $links[0]['label']);
        $this->assertStringContainsString('page=branding-extra', $links[0]['url']);
        $this->assertStringContainsString('admin.php', $links[0]['url']);
        $this->assertSame('manage_options', $links[0]['capability']);

        $primary = AP_Admin::pluginSettingsActionLink('branding/branding.php', $this->pluginDb());
        $this->assertIsArray($primary);
        $this->assertSame('branding-extra', $primary['id']);
        $this->assertSame('Settings', $primary['label']);
        $this->assertStringContainsString('page=branding-extra', $primary['url']);

        // Other plugin still inactive / no pages when not matching.
        $this->assertSame(
            [],
            AP_Admin::pluginSettingsActionLinks('other/other.php', $this->pluginDb())
        );
        // Unrelated basename.
        $this->assertSame(
            [],
            AP_Admin::pluginSettingsActionLinks('missing/plugin.php', $this->pluginDb())
        );

        $this->shutdownPluginSubsystem();
    }

    public function testPluginSettingsActionLinksNormalizeBasename(): void
    {
        $this->bootPluginSubsystem();

        AP_Admin_Menu::register([
            'id' => 'slash-test',
            'plugin' => 'foo/bar.php',
            'callback' => static function (): void {
            },
        ]);
        \AP_Options::update('active_plugins', ['foo/bar.php'], $this->pluginDb());

        $this->assertSame('foo/bar.php', AP_Admin::normalizePluginBasename('\\foo\\bar.php'));
        $this->assertSame('foo/bar.php', AP_Admin::normalizePluginBasename('/foo/bar.php'));

        $links = AP_Admin::pluginSettingsActionLinks('\\foo\\bar.php', $this->pluginDb());
        $this->assertCount(1, $links);
        $this->assertSame('slash-test', $links[0]['id']);

        $this->shutdownPluginSubsystem();
    }

    public function testPluginsPhpRendersSettingsActionLink(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/plugins.php');
        $this->assertStringContainsString('pluginSettingsActionLink', $src);
        $this->assertStringContainsString('ap-plugin-settings-link', $src);
        // Only when active (helper also enforces; screen gates on $isActive).
        $this->assertMatchesRegularExpression(
            '/\$settingsLink\s*=\s*\$isActive\s*\?/s',
            $src
        );
        // Settings href comes from the helper (router URL), not a crafted path include.
        $this->assertStringContainsString("\$settingsLink['url']", $src);
        $this->assertStringNotContainsString('include $settings', $src);
        $this->assertStringNotContainsString('require $settings', $src);
    }

    /**
     * Manual smoke (automated): ship Logos demo, register under Settings, sidebar
     * + plugins list Settings link appear only when the plugin is active.
     */
    public function testLogosDemoPluginRegistersSidebarAndPluginsListLink(): void
    {
        $demo = $this->root . '/ap-content/plugins/logos/logos.php';
        $this->assertFileExists($demo, 'Sample Logos plugin must ship for manual ACP smoke');

        $src = (string) file_get_contents($demo);
        $this->assertStringContainsString('Plugin Name: Logos', $src);
        $this->assertStringContainsString('ap_register_admin_page', $src);
        $this->assertStringContainsString("'id' => 'logos'", $src);
        $this->assertStringContainsString("'parent' => 'settings'", $src);
        $this->assertStringContainsString('logos_render_settings', $src);

        if (!defined('AP_ABSPATH')) {
            define('AP_ABSPATH', $this->root . '/');
        }
        require_once $this->root . '/ap-includes/functions.php';

        $this->bootPluginSubsystem();
        // Discover/load against the real plugins tree (not a temp override).
        AP_Plugin::setPluginsRootOverride(null);

        $headers = AP_Plugin::getPluginHeaders('logos/logos.php');
        $this->assertIsArray($headers);
        $this->assertSame('Logos', $headers['Plugin Name'] ?? null);

        // Load the sample plugin (same path as an active include).
        require $demo;

        $page = AP_Admin_Menu::get('logos');
        $this->assertIsArray($page);
        $this->assertSame('logos', $page['id']);
        $this->assertSame('settings', $page['parent']);
        $this->assertSame('Logos', $page['menu']);
        $this->assertSame('logos/logos.php', $page['plugin']);
        $this->assertInstanceOf(\AP_Admin_String_Callback::class, $page['callback']);
        $this->assertSame('logos_render_settings', $page['callback']->target());
        $this->assertIsCallable($page['callback']);
        $this->assertSame('manage_options', $page['capability']);

        // Inactive → no sidebar item, no plugins list Settings link.
        $inactiveIds = array_column(AP_Admin::menuItems('', $this->pluginDb()), 'id');
        $this->assertNotContains('logos', $inactiveIds);
        $this->assertNull(
            AP_Admin::pluginSettingsActionLink('logos/logos.php', $this->pluginDb())
        );

        // Active → Settings section sidebar entry + plugins.php Settings action.
        \AP_Options::update('active_plugins', ['logos/logos.php'], $this->pluginDb());

        $menu = AP_Admin::menuItems('logos', $this->pluginDb());
        $logosItems = array_values(array_filter(
            $menu,
            static fn (array $i): bool => $i['id'] === 'logos'
        ));
        $this->assertCount(1, $logosItems);
        $this->assertSame('Logos', $logosItems[0]['label']);
        $this->assertSame('settings', $logosItems[0]['section']);
        $this->assertTrue($logosItems[0]['active']);
        $this->assertStringContainsString('admin.php', $logosItems[0]['url']);
        $this->assertStringContainsString('page=logos', $logosItems[0]['url']);
        $this->assertSame('manage_options', $logosItems[0]['cap']);

        // Sits after core settings items (contiguous settings block).
        $ids = array_column($menu, 'id');
        $logosIdx = array_search('logos', $ids, true);
        $generalIdx = array_search('options-general', $ids, true);
        $this->assertNotFalse($logosIdx);
        $this->assertNotFalse($generalIdx);
        $this->assertGreaterThan($generalIdx, $logosIdx);

        $settingsLink = AP_Admin::pluginSettingsActionLink(
            'logos/logos.php',
            $this->pluginDb()
        );
        $this->assertIsArray($settingsLink);
        $this->assertSame('logos', $settingsLink['id']);
        $this->assertSame('Settings', $settingsLink['label']);
        $this->assertStringContainsString('admin.php', $settingsLink['url']);
        $this->assertStringContainsString('page=logos', $settingsLink['url']);

        // Callback renders inside chrome-safe HTML (no path include from query).
        $this->assertTrue(function_exists('logos_render_settings'));
        ob_start();
        $invoked = AP_Admin::invokeAdminPageCallback('logos_render_settings');
        $output = (string) ob_get_clean();
        $this->assertTrue($invoked);
        $this->assertStringContainsString('Logos', $output);
        $this->assertStringContainsString('logos-settings', $output);

        $this->shutdownPluginSubsystem();
    }

    /**
     * Phase 4: admin bootstrap fires ap_admin_menu + admin_menu after login.
     */
    public function testAdminBootstrapFiresAdminMenuHooks(): void
    {
        $boot = (string) file_get_contents($this->root . '/ap-admin/admin-bootstrap.php');

        $this->assertStringContainsString('AP_Admin::fireAdminMenu()', $boot);
        // Only after auth — not on login ($ap_admin_skip_auth).
        $skipPos = strpos($boot, 'empty($ap_admin_skip_auth)');
        $firePos = strpos($boot, 'AP_Admin::fireAdminMenu()');
        $this->assertNotFalse($skipPos);
        $this->assertNotFalse($firePos);
        $this->assertGreaterThan($skipPos, $firePos);

        $adminSrc = (string) file_get_contents($this->root . '/ap-admin/includes/class-ap-admin.php');
        $this->assertStringContainsString('function fireAdminMenu', $adminSrc);
        $this->assertStringContainsString("ADMIN_MENU_HOOK = 'ap_admin_menu'", $adminSrc);
        $this->assertStringContainsString("ADMIN_MENU_HOOK_WP = 'admin_menu'", $adminSrc);
    }

    public function testFireAdminMenuRunsNativeThenWpAliasOnce(): void
    {
        require_once $this->root . '/ap-includes/hooks.php';
        ap_reset_hooks();

        $order = [];
        ap_add_action('ap_admin_menu', static function () use (&$order): void {
            $order[] = 'ap_admin_menu';
        });
        ap_add_action('admin_menu', static function () use (&$order): void {
            $order[] = 'admin_menu';
        });

        $this->assertSame(0, ap_did_action('ap_admin_menu'));
        $this->assertSame(0, ap_did_action('admin_menu'));

        $this->assertTrue(AP_Admin::fireAdminMenu());
        $this->assertSame(['ap_admin_menu', 'admin_menu'], $order);
        $this->assertSame(1, ap_did_action('ap_admin_menu'));
        $this->assertSame(1, ap_did_action('admin_menu'));

        // Second call in the same request is a no-op.
        $this->assertFalse(AP_Admin::fireAdminMenu());
        $this->assertSame(['ap_admin_menu', 'admin_menu'], $order);
        $this->assertSame(1, ap_did_action('ap_admin_menu'));
        $this->assertSame(1, ap_did_action('admin_menu'));

        ap_reset_hooks();
    }

    public function testFireAdminMenuAllowsRegisteringPagesViaHook(): void
    {
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/functions.php';
        ap_reset_hooks();
        AP_Admin_Menu::reset();

        ap_add_action('ap_admin_menu', static function (): void {
            ap_register_admin_page([
                'id' => 'hooked-page',
                'parent' => 'settings',
                'title' => 'Hooked',
                'menu' => 'Hooked',
                'capability' => 'manage_options',
                'callback' => static function (): void {
                },
                'position' => 20,
            ]);
        });

        // Also listen on the WP alias (should not double-register; second id wins as separate).
        ap_add_action('admin_menu', static function (): void {
            ap_register_admin_page([
                'id' => 'wp-alias-page',
                'parent' => 'plugins',
                'title' => 'WP Alias',
                'menu' => 'WP Alias',
                'callback' => 'ap_admin_menu_test_render_placeholder_unused',
            ]);
        });

        $this->assertNull(AP_Admin_Menu::get('hooked-page'));
        $this->assertTrue(AP_Admin::fireAdminMenu());

        $hooked = AP_Admin_Menu::get('hooked-page');
        $this->assertIsArray($hooked);
        $this->assertSame('settings', $hooked['parent']);
        $this->assertSame('Hooked', $hooked['menu']);

        $alias = AP_Admin_Menu::get('wp-alias-page');
        $this->assertIsArray($alias);
        $this->assertSame('plugins', $alias['parent']);

        ap_reset_hooks();
        AP_Admin_Menu::reset();
    }
}

/**
 * Test double for string Class::method admin page callbacks.
 */
final class AdminRouterTestCallback
{
    public static bool $hit = false;

    public static function mark(): void
    {
        self::$hit = true;
    }
}
