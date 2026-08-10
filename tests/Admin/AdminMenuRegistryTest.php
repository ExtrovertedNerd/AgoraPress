<?php

/**
 * Tests for AP_Admin_Menu — registered ACP admin page store.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Admin_Menu;
use AP_Admin_String_Callback;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Admin_Menu::class)]
#[CoversClass(AP_Admin_String_Callback::class)]
final class AdminMenuRegistryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-admin-menu.php';
        AP_Admin_Menu::reset();
    }

    protected function tearDown(): void
    {
        AP_Admin_Menu::reset();
    }

    public function testClassFileIsReadable(): void
    {
        $this->assertFileIsReadable($this->root . '/ap-includes/class-ap-admin-menu.php');
    }

    public function testEmptyRegistry(): void
    {
        $this->assertSame([], AP_Admin_Menu::all());
        $this->assertSame([], AP_Admin_Menu::allSorted());
        $this->assertNull(AP_Admin_Menu::get('logos'));
        $this->assertFalse(AP_Admin_Menu::exists('logos'));
    }

    public function testRegisterStoresNormalizedPage(): void
    {
        $cb = static function (): void {
        };

        $ok = AP_Admin_Menu::register([
            'id' => 'logos',
            'parent' => 'settings',
            'title' => 'Logos',
            'menu' => 'Logos',
            'capability' => 'manage_options',
            'callback' => $cb,
            'plugin' => 'logos/logos.php',
            'position' => 40,
        ]);

        $this->assertTrue($ok);
        $this->assertTrue(AP_Admin_Menu::exists('logos'));

        $page = AP_Admin_Menu::get('logos');
        $this->assertIsArray($page);
        $this->assertSame('logos', $page['id']);
        $this->assertSame('settings', $page['parent']);
        $this->assertSame('Logos', $page['title']);
        $this->assertSame('Logos', $page['menu']);
        $this->assertSame('manage_options', $page['capability']);
        $this->assertSame($cb, $page['callback']);
        $this->assertSame('logos/logos.php', $page['plugin']);
        $this->assertSame(40, $page['position']);

        $all = AP_Admin_Menu::all();
        $this->assertArrayHasKey('logos', $all);
        $this->assertCount(1, $all);
    }

    public function testRegisterAppliesDefaults(): void
    {
        $ok = AP_Admin_Menu::register([
            'id' => 'demo-tool',
            'callback' => static function (): void {
            },
        ]);
        $this->assertTrue($ok);

        $page = AP_Admin_Menu::get('demo-tool');
        $this->assertIsArray($page);
        $this->assertSame('', $page['parent']);
        $this->assertSame('demo-tool', $page['title']);
        $this->assertSame('demo-tool', $page['menu']);
        $this->assertSame(AP_Admin_Menu::DEFAULT_CAPABILITY, $page['capability']);
        $this->assertSame('', $page['plugin']);
        $this->assertSame(AP_Admin_Menu::DEFAULT_POSITION, $page['position']);
    }

    public function testRegisterRejectsMissingId(): void
    {
        $this->assertFalse(AP_Admin_Menu::register([
            'callback' => static function (): void {
            },
        ]));
        $this->assertFalse(AP_Admin_Menu::register([
            'id' => '',
            'callback' => static function (): void {
            },
        ]));
        $this->assertFalse(AP_Admin_Menu::register([
            'id' => '!!!',
            'callback' => static function (): void {
            },
        ]));
        $this->assertSame([], AP_Admin_Menu::all());
    }

    public function testRegisterRejectsMissingCallback(): void
    {
        $this->assertFalse(AP_Admin_Menu::register([
            'id' => 'no-cb',
            'title' => 'No Callback',
        ]));
        $this->assertFalse(AP_Admin_Menu::register([
            'id' => 'null-cb',
            'callback' => null,
        ]));
        $this->assertFalse(AP_Admin_Menu::register([
            'id' => 'empty-cb',
            'callback' => '',
        ]));
        $this->assertFalse(AP_Admin_Menu::register([
            'id' => 'bad-cb',
            'callback' => 123,
        ]));
        $this->assertSame([], AP_Admin_Menu::all());
    }

    public function testRegisterAcceptsStringFunctionName(): void
    {
        $ok = AP_Admin_Menu::register([
            'id' => 'string-cb',
            'title' => 'String Callback',
            'callback' => 'ap_admin_menu_test_render_placeholder',
        ]);
        $this->assertTrue($ok);
        $page = AP_Admin_Menu::get('string-cb');
        $this->assertIsArray($page);
        // String function names are normalized to callable wrappers.
        $this->assertInstanceOf(AP_Admin_String_Callback::class, $page['callback']);
        $this->assertSame('ap_admin_menu_test_render_placeholder', $page['callback']->target());
        $this->assertIsCallable($page['callback']);
    }

    public function testRegisterRejectsDuplicateId(): void
    {
        $first = static function (): string {
            return 'first';
        };
        $second = static function (): string {
            return 'second';
        };

        $this->assertTrue(AP_Admin_Menu::register([
            'id' => 'dup',
            'title' => 'First',
            'callback' => $first,
        ]));
        $this->assertFalse(AP_Admin_Menu::register([
            'id' => 'dup',
            'title' => 'Second',
            'callback' => $second,
        ]));

        $page = AP_Admin_Menu::get('dup');
        $this->assertIsArray($page);
        $this->assertSame('First', $page['title']);
        $this->assertSame($first, $page['callback']);
        $this->assertCount(1, AP_Admin_Menu::all());
    }

    public function testRegisterRejectsDuplicateIdAfterSanitize(): void
    {
        // Ids are lowercased/sanitized; "Logos" and "logos!" collide on "logos".
        $this->assertTrue(AP_Admin_Menu::register([
            'id' => 'Logos',
            'title' => 'First',
            'callback' => static function (): void {
            },
        ]));
        $this->assertFalse(AP_Admin_Menu::register([
            'id' => 'logos!',
            'title' => 'Second',
            'callback' => static function (): void {
            },
        ]));
        $this->assertTrue(AP_Admin_Menu::exists('logos'));
        $page = AP_Admin_Menu::get('LOGOS');
        $this->assertIsArray($page);
        $this->assertSame('First', $page['title']);
        $this->assertCount(1, AP_Admin_Menu::all());
    }

    public function testFailedRegistrationDoesNotOccupyId(): void
    {
        // Missing callback must not reserve the slug for a later valid register.
        $this->assertFalse(AP_Admin_Menu::register([
            'id' => 'retry-me',
            'title' => 'No Callback Yet',
        ]));
        $this->assertFalse(AP_Admin_Menu::exists('retry-me'));

        $this->assertTrue(AP_Admin_Menu::register([
            'id' => 'retry-me',
            'title' => 'Now Valid',
            'callback' => static function (): void {
            },
        ]));
        $page = AP_Admin_Menu::get('retry-me');
        $this->assertIsArray($page);
        $this->assertSame('Now Valid', $page['title']);
    }

    public function testSanitizeId(): void
    {
        $this->assertSame('logos', AP_Admin_Menu::sanitizeId('Logos'));
        $this->assertSame('my-page_1', AP_Admin_Menu::sanitizeId(' my-page_1 '));
        $this->assertSame('clean', AP_Admin_Menu::sanitizeId('c!l@e#a$n'));
        $this->assertSame('', AP_Admin_Menu::sanitizeId(''));
        $this->assertSame('', AP_Admin_Menu::sanitizeId('!!!'));
    }

    public function testSanitizeParent(): void
    {
        $this->assertSame('settings', AP_Admin_Menu::sanitizeParent('Settings'));
        $this->assertSame('plugins', AP_Admin_Menu::sanitizeParent('plugins'));
        $this->assertSame('tools', AP_Admin_Menu::sanitizeParent('tools'));
        $this->assertSame('', AP_Admin_Menu::sanitizeParent(''));
        $this->assertSame('', AP_Admin_Menu::sanitizeParent('dashboard'));
        $this->assertSame(
            ['settings', 'plugins', 'tools', ''],
            AP_Admin_Menu::allowedParents()
        );
    }

    public function testAllSortedByPositionThenId(): void
    {
        AP_Admin_Menu::register([
            'id' => 'zebra',
            'callback' => static function (): void {
            },
            'position' => 10,
        ]);
        AP_Admin_Menu::register([
            'id' => 'alpha',
            'callback' => static function (): void {
            },
            'position' => 10,
        ]);
        AP_Admin_Menu::register([
            'id' => 'first',
            'callback' => static function (): void {
            },
            'position' => 5,
        ]);

        $sorted = AP_Admin_Menu::allSorted();
        $this->assertSame(['first', 'alpha', 'zebra'], array_column($sorted, 'id'));
    }

    public function testForPluginLookup(): void
    {
        AP_Admin_Menu::register([
            'id' => 'logos-settings',
            'callback' => static function (): void {
            },
            'plugin' => 'logos/logos.php',
        ]);
        AP_Admin_Menu::register([
            'id' => 'other',
            'callback' => static function (): void {
            },
            'plugin' => 'other/other.php',
        ]);
        AP_Admin_Menu::register([
            'id' => 'no-plugin',
            'callback' => static function (): void {
            },
        ]);

        $pages = AP_Admin_Menu::forPlugin('logos/logos.php');
        $this->assertCount(1, $pages);
        $this->assertSame('logos-settings', $pages[0]['id']);
        $this->assertSame([], AP_Admin_Menu::forPlugin('missing/plugin.php'));
        $this->assertSame([], AP_Admin_Menu::forPlugin(''));
    }

    public function testRemoveAndReset(): void
    {
        AP_Admin_Menu::register([
            'id' => 'a',
            'callback' => static function (): void {
            },
        ]);
        AP_Admin_Menu::register([
            'id' => 'b',
            'callback' => static function (): void {
            },
        ]);
        $this->assertTrue(AP_Admin_Menu::remove('a'));
        $this->assertFalse(AP_Admin_Menu::exists('a'));
        $this->assertTrue(AP_Admin_Menu::exists('b'));
        $this->assertFalse(AP_Admin_Menu::remove('a'));

        AP_Admin_Menu::reset();
        $this->assertSame([], AP_Admin_Menu::all());
    }

    public function testBootstrapLoadsAdminMenuClass(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-includes/bootstrap.php');
        $this->assertStringContainsString('class-ap-admin-menu.php', $src);
    }

    public function testIsValidCallback(): void
    {
        $this->assertTrue(AP_Admin_Menu::isValidCallback(static function (): void {
        }));
        $this->assertTrue(AP_Admin_Menu::isValidCallback('my_plugin_render'));
        $this->assertTrue(AP_Admin_Menu::isValidCallback('My_Plugin::render'));
        $this->assertFalse(AP_Admin_Menu::isValidCallback(null));
        $this->assertFalse(AP_Admin_Menu::isValidCallback(''));
        $this->assertFalse(AP_Admin_Menu::isValidCallback('bad name'));
        $this->assertFalse(AP_Admin_Menu::isValidCallback(42));
    }

    public function testWpParentMapCoversCoreSections(): void
    {
        $map = AP_Admin_Menu::wpParentMap();
        $this->assertIsArray($map);
        $this->assertNotEmpty($map);

        // Canonical SPEC examples.
        $this->assertSame('settings', $map['options-general.php']);
        $this->assertSame('plugins', $map['plugins.php']);
        $this->assertSame('tools', $map['tools.php']);

        // Every value must be an allowed registry parent (non-empty).
        $allowed = array_filter(AP_Admin_Menu::allowedParents(), static fn (string $p): bool => $p !== '');
        foreach ($map as $file => $parent) {
            $this->assertIsString($file);
            $this->assertStringEndsWith('.php', $file);
            $this->assertSame(strtolower($file), $file, 'Map keys must be lowercase basenames');
            $this->assertContains($parent, $allowed, "Unexpected parent {$parent} for {$file}");
        }
    }

    public function testMapWpParent(): void
    {
        // Settings (WP + AgoraPress options screens).
        $this->assertSame('settings', AP_Admin_Menu::mapWpParent('options-general.php'));
        $this->assertSame('settings', AP_Admin_Menu::mapWpParent('OPTIONS-GENERAL.PHP'));
        $this->assertSame('settings', AP_Admin_Menu::mapWpParent('options.php'));
        $this->assertSame('settings', AP_Admin_Menu::mapWpParent('options-writing.php'));
        $this->assertSame('settings', AP_Admin_Menu::mapWpParent('options-reading.php'));
        $this->assertSame('settings', AP_Admin_Menu::mapWpParent('options-discussion.php'));
        $this->assertSame('settings', AP_Admin_Menu::mapWpParent('options-media.php'));
        $this->assertSame('settings', AP_Admin_Menu::mapWpParent('options-permalink.php'));
        $this->assertSame('settings', AP_Admin_Menu::mapWpParent('options-privacy.php'));
        $this->assertSame('settings', AP_Admin_Menu::mapWpParent('options-modules.php'));
        $this->assertSame('settings', AP_Admin_Menu::mapWpParent('options-forums.php'));
        $this->assertSame('settings', AP_Admin_Menu::mapWpParent('options-hall-of-fame.php'));
        $this->assertSame('settings', AP_Admin_Menu::mapWpParent('settings.php'));

        // Plugins.
        $this->assertSame('plugins', AP_Admin_Menu::mapWpParent('plugins.php'));
        $this->assertSame('plugins', AP_Admin_Menu::mapWpParent('plugin-install.php'));
        $this->assertSame('plugins', AP_Admin_Menu::mapWpParent('plugin-editor.php'));

        // Tools.
        $this->assertSame('tools', AP_Admin_Menu::mapWpParent('tools.php'));
        $this->assertSame('tools', AP_Admin_Menu::mapWpParent('import.php'));
        $this->assertSame('tools', AP_Admin_Menu::mapWpParent('export.php'));
        $this->assertSame('tools', AP_Admin_Menu::mapWpParent('site-health.php'));
        $this->assertSame('tools', AP_Admin_Menu::mapWpParent('export-personal-data.php'));
        $this->assertSame('tools', AP_Admin_Menu::mapWpParent('erase-personal-data.php'));
        $this->assertSame('tools', AP_Admin_Menu::mapWpParent('update-core.php'));
        $this->assertSame('tools', AP_Admin_Menu::mapWpParent('analytics.php'));
        $this->assertSame('tools', AP_Admin_Menu::mapWpParent('management.php'));

        // Native section keys pass through.
        $this->assertSame('settings', AP_Admin_Menu::mapWpParent('settings'));
        $this->assertSame('plugins', AP_Admin_Menu::mapWpParent('plugins'));
        $this->assertSame('tools', AP_Admin_Menu::mapWpParent('tools'));
        $this->assertSame('', AP_Admin_Menu::mapWpParent(''));

        // Path / query / fragment noise stripped to basename.
        $this->assertSame('settings', AP_Admin_Menu::mapWpParent('/wp-admin/options-general.php?page=x'));
        $this->assertSame('plugins', AP_Admin_Menu::mapWpParent('ap-admin/plugins.php#top'));
        $this->assertSame('tools', AP_Admin_Menu::mapWpParent('\\wp-admin\\tools.php'));
        $this->assertSame('settings', AP_Admin_Menu::mapWpParent('  Options-General.PHP  '));

        // WP parents outside settings/plugins/tools → default section ('').
        $this->assertSame('', AP_Admin_Menu::mapWpParent('my-custom-top-level'));
        $this->assertSame('', AP_Admin_Menu::mapWpParent('themes.php'));
        $this->assertSame('', AP_Admin_Menu::mapWpParent('users.php'));
        $this->assertSame('', AP_Admin_Menu::mapWpParent('index.php'));
        $this->assertSame('', AP_Admin_Menu::mapWpParent('edit.php'));
        $this->assertSame('', AP_Admin_Menu::mapWpParent('edit.php?post_type=page'));
        $this->assertSame('', AP_Admin_Menu::mapWpParent('upload.php'));
        $this->assertSame('', AP_Admin_Menu::mapWpParent('edit-comments.php'));
        $this->assertSame('', AP_Admin_Menu::mapWpParent('nav-menus.php'));

        // Full map is authoritative for known files.
        foreach (AP_Admin_Menu::wpParentMap() as $file => $parent) {
            $this->assertSame($parent, AP_Admin_Menu::mapWpParent($file), "mapWpParent({$file})");
        }
    }

    public function testNormalizeCallback(): void
    {
        $closure = static function (): void {
        };
        $this->assertSame($closure, AP_Admin_Menu::normalizeCallback($closure));

        // String function names / Class::method → callable wrappers (late-bound).
        $wrapped = AP_Admin_Menu::normalizeCallback('my_plugin_render');
        $this->assertInstanceOf(AP_Admin_String_Callback::class, $wrapped);
        $this->assertSame('my_plugin_render', $wrapped->target());
        $this->assertIsCallable($wrapped);

        $trimmed = AP_Admin_Menu::normalizeCallback('  my_plugin_render  ');
        $this->assertInstanceOf(AP_Admin_String_Callback::class, $trimmed);
        $this->assertSame('my_plugin_render', $trimmed->target());

        $staticMethod = AP_Admin_Menu::normalizeCallback('My_Plugin::render');
        $this->assertInstanceOf(AP_Admin_String_Callback::class, $staticMethod);
        $this->assertSame('My_Plugin::render', $staticMethod->target());

        // Already-wrapped instances pass through.
        $this->assertSame($wrapped, AP_Admin_Menu::normalizeCallback($wrapped));

        $this->assertNull(AP_Admin_Menu::normalizeCallback(null));
        $this->assertNull(AP_Admin_Menu::normalizeCallback(''));
        $this->assertNull(AP_Admin_Menu::normalizeCallback(false));
        $this->assertNull(AP_Admin_Menu::normalizeCallback('bad name'));
        $this->assertNull(AP_Admin_Menu::normalizeCallback(42));
    }

    public function testStringCallbackWrapperLateBinding(): void
    {
        // Register with a Class::method string before the class exists.
        $target = 'AP_AdminMenuLateBound_RenderStub::render';
        $this->assertFalse(class_exists('AP_AdminMenuLateBound_RenderStub', false));

        $this->assertTrue(AP_Admin_Menu::register([
            'id' => 'late-cb',
            'title' => 'Late',
            'callback' => $target,
        ]));
        $page = AP_Admin_Menu::get('late-cb');
        $this->assertIsArray($page);
        $this->assertInstanceOf(AP_Admin_String_Callback::class, $page['callback']);
        $this->assertSame($target, $page['callback']->target());
        $this->assertFalse($page['callback']->isResolved());
        $this->assertNull($page['callback']->resolve());

        // Unresolved wrapper → invoke soft-fails (no fatal).
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        $this->assertNull(\AP_Admin::resolveAdminPageCallback($page['callback']));
        $this->assertFalse(\AP_Admin::invokeAdminPageCallback($page['callback']));

        // Define after registration — wrapper resolves at render time.
        if (!class_exists('AP_AdminMenuLateBound_RenderStub', false)) {
            eval(
                'final class AP_AdminMenuLateBound_RenderStub {'
                . ' public static function render(): void { echo \'late-bound-ok\'; }'
                . '}'
            );
        }

        $this->assertTrue($page['callback']->isResolved());
        $this->assertIsCallable(\AP_Admin::resolveAdminPageCallback($page['callback']));
        ob_start();
        $this->assertTrue(\AP_Admin::invokeAdminPageCallback($page['callback']));
        $out = (string) ob_get_clean();
        $this->assertSame('late-bound-ok', $out);
    }

    public function testWpHookName(): void
    {
        $this->assertSame('settings_page_logos', AP_Admin_Menu::wpHookName('settings', 'logos'));
        $this->assertSame('plugins_page_demo', AP_Admin_Menu::wpHookName('plugins', 'demo'));
        $this->assertSame('tools_page_tool', AP_Admin_Menu::wpHookName('tools', 'tool'));
        $this->assertSame('toplevel_page_top', AP_Admin_Menu::wpHookName('', 'top'));
        $this->assertSame('', AP_Admin_Menu::wpHookName('settings', '!!!'));
    }

    public function testRegisterFromWp(): void
    {
        $hook = AP_Admin_Menu::registerFromWp(
            'settings',
            'Page Title',
            'Menu Label',
            'manage_options',
            'from-wp',
            static function (): void {
            },
            30
        );
        $this->assertSame('settings_page_from-wp', $hook);

        $page = AP_Admin_Menu::get('from-wp');
        $this->assertIsArray($page);
        $this->assertSame('settings', $page['parent']);
        $this->assertSame('Page Title', $page['title']);
        $this->assertSame('Menu Label', $page['menu']);
        $this->assertSame('manage_options', $page['capability']);
        $this->assertSame(30, $page['position']);

        // String function-name callback normalized to callable wrapper.
        $hook2 = AP_Admin_Menu::registerFromWp(
            'plugins',
            'String CB',
            'String CB',
            'activate_plugins',
            'string-from-wp',
            'ap_admin_menu_test_render_placeholder'
        );
        $this->assertSame('plugins_page_string-from-wp', $hook2);
        $page2 = AP_Admin_Menu::get('string-from-wp');
        $this->assertIsArray($page2);
        $this->assertInstanceOf(AP_Admin_String_Callback::class, $page2['callback']);
        $this->assertSame('ap_admin_menu_test_render_placeholder', $page2['callback']->target());
        $this->assertIsCallable($page2['callback']);

        // Rejects empty / invalid callback and bad slug.
        $this->assertFalse(AP_Admin_Menu::registerFromWp('settings', 'T', 'M', 'manage_options', 'no-cb', ''));
        $this->assertFalse(AP_Admin_Menu::registerFromWp('settings', 'T', 'M', 'manage_options', '!!!', static function (): void {
        }));
        // Duplicate id.
        $this->assertFalse(AP_Admin_Menu::registerFromWp(
            'settings',
            'Dup',
            'Dup',
            'manage_options',
            'from-wp',
            static function (): void {
            }
        ));
    }

    public function testAddOptionsPageShim(): void
    {
        require_once $this->root . '/ap-includes/functions.php';

        $this->assertTrue(function_exists('add_options_page'));

        $hook = add_options_page(
            'Options Title',
            'Options Menu',
            'manage_options',
            'opts-demo',
            static function (): void {
            },
            25
        );
        $this->assertSame('settings_page_opts-demo', $hook);

        $page = AP_Admin_Menu::get('opts-demo');
        $this->assertIsArray($page);
        $this->assertSame('settings', $page['parent']);
        $this->assertSame('Options Title', $page['title']);
        $this->assertSame('Options Menu', $page['menu']);
        $this->assertSame('manage_options', $page['capability']);
        $this->assertSame(25, $page['position']);
    }

    public function testAddPluginsPageShim(): void
    {
        require_once $this->root . '/ap-includes/functions.php';

        $this->assertTrue(function_exists('add_plugins_page'));

        $hook = add_plugins_page(
            'Plugins Title',
            'Plugins Menu',
            'activate_plugins',
            'plug-demo',
            'ap_admin_menu_test_render_placeholder'
        );
        $this->assertSame('plugins_page_plug-demo', $hook);

        $page = AP_Admin_Menu::get('plug-demo');
        $this->assertIsArray($page);
        $this->assertSame('plugins', $page['parent']);
        $this->assertInstanceOf(AP_Admin_String_Callback::class, $page['callback']);
        $this->assertSame('ap_admin_menu_test_render_placeholder', $page['callback']->target());
        $this->assertIsCallable($page['callback']);
    }

    public function testAddMenuPageShim(): void
    {
        require_once $this->root . '/ap-includes/functions.php';

        $this->assertTrue(function_exists('add_menu_page'));

        $hook = add_menu_page(
            'Top Title',
            'Top Menu',
            'manage_options',
            'top-demo',
            static function (): void {
            },
            'dashicons-admin-generic',
            5
        );
        $this->assertSame('toplevel_page_top-demo', $hook);

        $page = AP_Admin_Menu::get('top-demo');
        $this->assertIsArray($page);
        $this->assertSame('', $page['parent']);
        $this->assertSame('Top Title', $page['title']);
        $this->assertSame(5, $page['position']);
    }

    public function testAddSubmenuPageShimMapsParent(): void
    {
        require_once $this->root . '/ap-includes/functions.php';

        $this->assertTrue(function_exists('add_submenu_page'));

        $hook = add_submenu_page(
            'options-general.php',
            'Sub Title',
            'Sub Menu',
            'manage_options',
            'sub-settings',
            static function (): void {
            }
        );
        $this->assertSame('settings_page_sub-settings', $hook);
        $this->assertSame('settings', AP_Admin_Menu::get('sub-settings')['parent'] ?? null);

        $hook2 = add_submenu_page(
            'plugins.php',
            'Sub Plug',
            'Sub Plug',
            'manage_options',
            'sub-plugins',
            static function (): void {
            }
        );
        $this->assertSame('plugins_page_sub-plugins', $hook2);
        $this->assertSame('plugins', AP_Admin_Menu::get('sub-plugins')['parent'] ?? null);

        $hook3 = add_submenu_page(
            'tools.php',
            'Sub Tool',
            'Sub Tool',
            'manage_options',
            'sub-tools',
            static function (): void {
            }
        );
        $this->assertSame('tools_page_sub-tools', $hook3);
        $this->assertSame('tools', AP_Admin_Menu::get('sub-tools')['parent'] ?? null);

        // Native parent keys also accepted.
        $hook4 = add_submenu_page(
            'settings',
            'Native Parent',
            'Native Parent',
            'manage_options',
            'sub-native',
            static function (): void {
            }
        );
        $this->assertSame('settings_page_sub-native', $hook4);

        // Path-style parent still maps via basename.
        $hook5 = add_submenu_page(
            '/wp-admin/options-general.php',
            'Path Parent',
            'Path Parent',
            'manage_options',
            'sub-path-opts',
            static function (): void {
            }
        );
        $this->assertSame('settings_page_sub-path-opts', $hook5);
        $this->assertSame('settings', AP_Admin_Menu::get('sub-path-opts')['parent'] ?? null);

        // Unknown WP parent (themes.php) → default section ('').
        $hook6 = add_submenu_page(
            'themes.php',
            'Theme Sub',
            'Theme Sub',
            'manage_options',
            'sub-themes',
            static function (): void {
            }
        );
        $this->assertSame('toplevel_page_sub-themes', $hook6);
        $this->assertSame('', AP_Admin_Menu::get('sub-themes')['parent'] ?? null);
    }

    public function testWpShimsRejectInvalidInput(): void
    {
        require_once $this->root . '/ap-includes/functions.php';

        $this->assertFalse(add_options_page('T', 'M', 'manage_options', 'bad-opts', ''));
        $this->assertFalse(add_plugins_page('T', 'M', 'manage_options', '', static function (): void {
        }));
        $this->assertFalse(add_menu_page('T', 'M', 'manage_options', '!!!', static function (): void {
        }));
        $this->assertFalse(add_submenu_page('options-general.php', 'T', 'M', 'manage_options', 'nope', null));
        $this->assertSame([], AP_Admin_Menu::all());
    }

    public function testWpShimsViaAdminMenuHook(): void
    {
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';

        ap_reset_hooks();
        AP_Admin_Menu::reset();

        ap_add_action('admin_menu', static function (): void {
            add_options_page(
                'Hooked Options',
                'Hooked Options',
                'manage_options',
                'hooked-opts',
                static function (): void {
                }
            );
            add_plugins_page(
                'Hooked Plugins',
                'Hooked Plugins',
                'manage_options',
                'hooked-plug',
                'ap_admin_menu_test_render_placeholder'
            );
        });

        $this->assertTrue(\AP_Admin::fireAdminMenu());
        $this->assertIsArray(AP_Admin_Menu::get('hooked-opts'));
        $this->assertSame('settings', AP_Admin_Menu::get('hooked-opts')['parent'] ?? null);
        $this->assertIsArray(AP_Admin_Menu::get('hooked-plug'));
        $this->assertSame('plugins', AP_Admin_Menu::get('hooked-plug')['parent'] ?? null);

        ap_reset_hooks();
        AP_Admin_Menu::reset();
    }

    public function testProceduralRegisterAdminPage(): void
    {
        require_once $this->root . '/ap-includes/functions.php';

        $this->assertTrue(function_exists('ap_register_admin_page'));

        $cb = static function (): void {
        };
        $ok = ap_register_admin_page([
            'id' => 'logos',
            'parent' => 'settings',
            'title' => 'Logos',
            'menu' => 'Logos',
            'capability' => 'manage_options',
            'callback' => $cb,
            'plugin' => 'logos/logos.php',
            'position' => 40,
        ]);

        $this->assertTrue($ok);
        $page = AP_Admin_Menu::get('logos');
        $this->assertIsArray($page);
        $this->assertSame('logos', $page['id']);
        $this->assertSame('settings', $page['parent']);
        $this->assertSame('Logos', $page['title']);
        $this->assertSame('manage_options', $page['capability']);
        $this->assertSame($cb, $page['callback']);
        $this->assertSame('logos/logos.php', $page['plugin']);
        $this->assertSame(40, $page['position']);
    }

    public function testProceduralRegisterRejectsDuplicateId(): void
    {
        require_once $this->root . '/ap-includes/functions.php';

        $this->assertTrue(ap_register_admin_page([
            'id' => 'dup-proc',
            'title' => 'First',
            'callback' => static function (): void {
            },
        ]));
        $this->assertFalse(ap_register_admin_page([
            'id' => 'dup-proc',
            'title' => 'Second',
            'callback' => static function (): void {
            },
        ]));

        $page = AP_Admin_Menu::get('dup-proc');
        $this->assertIsArray($page);
        $this->assertSame('First', $page['title']);
        $this->assertCount(1, AP_Admin_Menu::all());
    }

    public function testProceduralRegisterRejectsMissingCallback(): void
    {
        require_once $this->root . '/ap-includes/functions.php';

        $this->assertFalse(ap_register_admin_page([
            'id' => 'no-cb-proc',
            'title' => 'No Callback',
        ]));
        $this->assertFalse(ap_register_admin_page([
            'id' => 'null-cb-proc',
            'callback' => null,
        ]));
        $this->assertFalse(ap_register_admin_page([
            'id' => 'bad-cb-proc',
            'callback' => 123,
        ]));
        $this->assertSame([], AP_Admin_Menu::all());
    }

    public function testProceduralRegisterRejectsMissingId(): void
    {
        require_once $this->root . '/ap-includes/functions.php';

        $this->assertFalse(ap_register_admin_page([
            'callback' => static function (): void {
            },
        ]));
        $this->assertFalse(ap_register_admin_page([
            'id' => '',
            'callback' => static function (): void {
            },
        ]));
        $this->assertFalse(ap_register_admin_page([
            'id' => '!!!',
            'callback' => static function (): void {
            },
        ]));
        $this->assertSame([], AP_Admin_Menu::all());
    }

    public function testProceduralRegisterDefaultCapability(): void
    {
        require_once $this->root . '/ap-includes/functions.php';

        $this->assertTrue(ap_register_admin_page([
            'id' => 'cap-default',
            'callback' => static function (): void {
            },
        ]));
        $page = AP_Admin_Menu::get('cap-default');
        $this->assertIsArray($page);
        $this->assertSame(AP_Admin_Menu::DEFAULT_CAPABILITY, $page['capability']);

        $this->assertTrue(ap_register_admin_page([
            'id' => 'cap-empty',
            'capability' => '   ',
            'callback' => static function (): void {
            },
        ]));
        $pageEmpty = AP_Admin_Menu::get('cap-empty');
        $this->assertIsArray($pageEmpty);
        $this->assertSame(AP_Admin_Menu::DEFAULT_CAPABILITY, $pageEmpty['capability']);

        $this->assertTrue(ap_register_admin_page([
            'id' => 'cap-custom',
            'capability' => 'edit_posts',
            'callback' => static function (): void {
            },
        ]));
        $pageCustom = AP_Admin_Menu::get('cap-custom');
        $this->assertIsArray($pageCustom);
        $this->assertSame('edit_posts', $pageCustom['capability']);
    }

    public function testProceduralGetAdminPage(): void
    {
        require_once $this->root . '/ap-includes/functions.php';

        $this->assertTrue(function_exists('ap_get_admin_page'));
        $this->assertNull(ap_get_admin_page('missing'));
        $this->assertNull(ap_get_admin_page(''));
        $this->assertNull(ap_get_admin_page('!!!'));

        $cb = static function (): void {
        };
        $this->assertTrue(ap_register_admin_page([
            'id' => 'logos',
            'parent' => 'settings',
            'title' => 'Logos',
            'menu' => 'Logos',
            'callback' => $cb,
            'plugin' => 'logos/logos.php',
            'position' => 40,
        ]));

        $page = ap_get_admin_page('logos');
        $this->assertIsArray($page);
        $this->assertSame('logos', $page['id']);
        $this->assertSame('settings', $page['parent']);
        $this->assertSame('Logos', $page['title']);
        $this->assertSame($cb, $page['callback']);
        $this->assertSame('logos/logos.php', $page['plugin']);
        $this->assertSame(40, $page['position']);

        // Sanitization matches class getter (case / junk stripped).
        $viaDirty = ap_get_admin_page('LoGoS!');
        $this->assertIsArray($viaDirty);
        $this->assertSame('logos', $viaDirty['id']);
        $this->assertSame($page, AP_Admin_Menu::get('logos'));
    }

    public function testProceduralGetAdminPagesListHelpers(): void
    {
        require_once $this->root . '/ap-includes/functions.php';

        $this->assertTrue(function_exists('ap_get_admin_pages'));
        $this->assertTrue(function_exists('ap_get_admin_pages_sorted'));
        $this->assertTrue(function_exists('ap_get_admin_pages_for_plugin'));

        $this->assertSame([], ap_get_admin_pages());
        $this->assertSame([], ap_get_admin_pages_sorted());
        $this->assertSame([], ap_get_admin_pages_for_plugin('logos/logos.php'));

        ap_register_admin_page([
            'id' => 'zebra',
            'callback' => static function (): void {
            },
            'position' => 10,
            'plugin' => 'logos/logos.php',
        ]);
        ap_register_admin_page([
            'id' => 'alpha',
            'callback' => static function (): void {
            },
            'position' => 10,
            'plugin' => 'other/other.php',
        ]);
        ap_register_admin_page([
            'id' => 'first',
            'callback' => static function (): void {
            },
            'position' => 5,
            'plugin' => 'logos/logos.php',
        ]);

        $all = ap_get_admin_pages();
        $this->assertCount(3, $all);
        $this->assertArrayHasKey('zebra', $all);
        $this->assertArrayHasKey('alpha', $all);
        $this->assertArrayHasKey('first', $all);
        $this->assertSame(['zebra', 'alpha', 'first'], array_keys($all));
        $this->assertSame(AP_Admin_Menu::all(), $all);

        $sorted = ap_get_admin_pages_sorted();
        $this->assertSame(['first', 'alpha', 'zebra'], array_column($sorted, 'id'));
        $this->assertSame(AP_Admin_Menu::allSorted(), $sorted);

        $forLogos = ap_get_admin_pages_for_plugin('logos/logos.php');
        $this->assertCount(2, $forLogos);
        $this->assertSame(['zebra', 'first'], array_column($forLogos, 'id'));
        $this->assertSame([], ap_get_admin_pages_for_plugin('missing/plugin.php'));
        $this->assertSame([], ap_get_admin_pages_for_plugin(''));
    }
}
