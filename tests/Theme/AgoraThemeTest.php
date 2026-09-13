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
use AP_Taxonomy;
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
        require_once $this->root . '/ap-includes/class-ap-registration.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-taxonomy.php';
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
        AP_Taxonomy::resetRegistry();
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
        $this->clearColorSchemePreviewRequest();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Post::ensureBuiltins();
        AP_Taxonomy::ensureBuiltins();

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
        AP_Taxonomy::resetRegistry();
        AP_Theme::reset();
        AP_Nav_Menu::reset();
        AP_Options::flushCache();
        AP_Rewrite::resetCache();
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post'], $GLOBALS['apdb']);
        $this->clearColorSchemePreviewRequest();
    }

    private function clearColorSchemePreviewRequest(): void
    {
        unset($_GET['agora_scheme'], $_COOKIE['agora_scheme']);
        if (defined('AGORA_COLOR_SCHEME_QUERY')) {
            unset($_GET[AGORA_COLOR_SCHEME_QUERY]);
        }
        if (defined('AGORA_COLOR_SCHEME_COOKIE')) {
            unset($_COOKIE[AGORA_COLOR_SCHEME_COOKIE]);
        }
    }

    private function storedColorSchemeValue(): string
    {
        $stored = $this->db->getVar(
            'SELECT option_value FROM ' . $this->db->quoteIdentifier($this->db->table('options'))
            . ' WHERE option_name = ? LIMIT 1',
            [AGORA_COLOR_SCHEME_OPTION]
        );

        return is_string($stored) ? $stored : '';
    }

    private function renderPublicHome(): string
    {
        AP_Post::insert([
            'post_title' => 'Scheme Preview Home ' . uniqid('', true),
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

        return (string) ob_get_clean();
    }

    /**
     * Public pages must not emit the visitor scheme-preview control.
     */
    private function assertNoVisitorColorPreviewMarkup(string $html): void
    {
        $this->assertStringNotContainsString('agora-scheme-preview', $html);
        $this->assertStringNotContainsString('agora-scheme-preview__swatch', $html);
        $this->assertStringNotContainsString('Preview color scheme', $html);
        $this->assertStringNotContainsString('agora_scheme=', $html);
    }

    /**
     * Body of a top-level function in agora/functions.php (brace-matched).
     */
    private function agoraFunctionBody(string $name): string
    {
        $src = (string) file_get_contents($this->root . '/ap-content/themes/agora/functions.php');
        $needle = 'function ' . $name . '(';
        $start = strpos($src, $needle);
        $this->assertNotFalse($start, 'missing function ' . $name);
        $brace = strpos($src, '{', (int) $start);
        $this->assertNotFalse($brace, 'missing body for ' . $name);
        $depth = 0;
        $len = strlen($src);
        for ($i = (int) $brace; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, (int) $brace, $i - (int) $brace + 1);
                }
            }
        }
        $this->fail('unclosed function ' . $name);
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
        $this->assertNoVisitorColorPreviewMarkup($html);
    }

    public function testVisitorColorPreviewDefaultsOffAndDoesNotWriteScheme(): void
    {
        $this->assertSame('agora_visitor_color_preview', AGORA_VISITOR_COLOR_PREVIEW_OPTION);
        $this->assertFalse(agora_visitor_color_preview_enabled($this->db));
        $this->assertFalse(agora_sanitize_visitor_color_preview(null));
        $this->assertFalse(agora_sanitize_visitor_color_preview('0'));
        $this->assertFalse(agora_sanitize_visitor_color_preview(''));
        $this->assertTrue(agora_sanitize_visitor_color_preview('1'));
        $this->assertTrue(agora_sanitize_visitor_color_preview('on'));

        $this->assertTrue(agora_set_color_scheme('parchment', $this->db));
        $this->assertTrue(agora_set_visitor_color_preview(true, $this->db));
        $this->assertTrue(agora_visitor_color_preview_enabled($this->db));
        $this->assertSame('parchment', agora_get_color_scheme($this->db));

        $stored = $this->db->getVar(
            'SELECT option_value FROM ' . $this->db->quoteIdentifier($this->db->table('options'))
            . ' WHERE option_name = ? LIMIT 1',
            [AGORA_VISITOR_COLOR_PREVIEW_OPTION]
        );
        $this->assertSame('1', $stored);

        $this->assertTrue(agora_set_visitor_color_preview(false, $this->db));
        $this->assertFalse(agora_visitor_color_preview_enabled($this->db));
        $this->assertSame('parchment', agora_get_color_scheme($this->db));
        $storedOff = $this->db->getVar(
            'SELECT option_value FROM ' . $this->db->quoteIdentifier($this->db->table('options'))
            . ' WHERE option_name = ? LIMIT 1',
            [AGORA_VISITOR_COLOR_PREVIEW_OPTION]
        );
        $this->assertSame('0', $storedOff);
    }

    public function testPreviewQueryWinsOverCookieAndOptionWithoutWritingScheme(): void
    {
        $this->assertTrue(agora_set_color_scheme('parchment', $this->db));
        $this->assertTrue(agora_set_visitor_color_preview(true, $this->db));
        $_COOKIE[AGORA_COLOR_SCHEME_COOKIE] = 'obsidian';
        $_GET[AGORA_COLOR_SCHEME_QUERY] = 'midnight';

        $this->assertSame('midnight', agora_get_color_scheme($this->db));
        $this->assertSame('dark', agora_get_color_scheme_mode(null, $this->db));
        $this->assertSame('parchment', agora_get_stored_color_scheme($this->db));
        $this->assertSame('parchment', $this->storedColorSchemeValue());
        // Valid query refreshes the preview cookie for later requests.
        $this->assertSame('midnight', $_COOKIE[AGORA_COLOR_SCHEME_COOKIE]);

        $classes = agora_body_class($this->db);
        $this->assertStringContainsString('agora-scheme-midnight', $classes);
        $this->assertStringContainsString('agora-mode-dark', $classes);
        $this->assertStringNotContainsString('agora-scheme-parchment', $classes);
        $this->assertStringNotContainsString('agora-scheme-obsidian', $classes);
    }

    public function testPreviewCookieWinsOverSiteOption(): void
    {
        $this->assertTrue(agora_set_color_scheme('parchment', $this->db));
        $this->assertTrue(agora_set_visitor_color_preview(true, $this->db));
        $_COOKIE[AGORA_COLOR_SCHEME_COOKIE] = 'obsidian';

        $this->assertSame('obsidian', agora_get_color_scheme($this->db));
        $this->assertSame('parchment', agora_get_stored_color_scheme($this->db));
        $this->assertSame('parchment', $this->storedColorSchemeValue());

        $classes = agora_body_class($this->db);
        $this->assertStringContainsString('agora-scheme-obsidian', $classes);
        $this->assertStringContainsString('agora-mode-dark', $classes);
    }

    public function testInvalidPreviewSlugIgnoredThenOptionThenMarble(): void
    {
        $this->assertTrue(agora_set_color_scheme('cloud', $this->db));
        $this->assertTrue(agora_set_visitor_color_preview(true, $this->db));
        $_GET[AGORA_COLOR_SCHEME_QUERY] = 'neon-disco';
        $_COOKIE[AGORA_COLOR_SCHEME_COOKIE] = 'midnight';

        $this->assertSame('midnight', agora_get_color_scheme($this->db));
        $this->assertSame('cloud', $this->storedColorSchemeValue());
        // Invalid query must not overwrite a valid cookie.
        $this->assertSame('midnight', $_COOKIE[AGORA_COLOR_SCHEME_COOKIE]);

        unset($_COOKIE[AGORA_COLOR_SCHEME_COOKIE]);
        $_GET[AGORA_COLOR_SCHEME_QUERY] = '../evil';
        $this->assertSame('cloud', agora_get_color_scheme($this->db));
        $this->assertSame('cloud', agora_get_stored_color_scheme($this->db));

        $_COOKIE[AGORA_COLOR_SCHEME_COOKIE] = 'not-a-scheme';
        $this->assertSame('cloud', agora_get_color_scheme($this->db));

        agora_write_option(AGORA_COLOR_SCHEME_OPTION, '', $this->db);
        $this->assertSame('marble', agora_get_stored_color_scheme($this->db));
        $this->assertSame('marble', agora_get_color_scheme($this->db));
        $this->assertSame('light', agora_get_color_scheme_mode(null, $this->db));
    }

    public function testPreviewIgnoredWhenVisitorPreviewOff(): void
    {
        $this->assertTrue(agora_set_color_scheme('parchment', $this->db));
        $this->assertTrue(agora_set_visitor_color_preview(false, $this->db));
        $_GET[AGORA_COLOR_SCHEME_QUERY] = 'midnight';
        $_COOKIE[AGORA_COLOR_SCHEME_COOKIE] = 'charcoal';

        $this->assertSame('parchment', agora_get_color_scheme($this->db));
        $this->assertSame('parchment', agora_get_stored_color_scheme($this->db));
        $this->assertSame('parchment', $this->storedColorSchemeValue());
        $this->assertSame('charcoal', $_COOKIE[AGORA_COLOR_SCHEME_COOKIE]);

        $classes = agora_body_class($this->db);
        $this->assertStringContainsString('agora-scheme-parchment', $classes);
        $this->assertStringContainsString('agora-mode-light', $classes);
        $this->assertStringNotContainsString('agora-scheme-midnight', $classes);
        $this->assertStringNotContainsString('agora-scheme-charcoal', $classes);
    }

    public function testRenderAppliesPreviewQueryWithoutChangingStoredScheme(): void
    {
        $this->assertTrue(agora_set_color_scheme('parchment', $this->db));
        $this->assertTrue(agora_set_visitor_color_preview(true, $this->db));
        $_GET[AGORA_COLOR_SCHEME_QUERY] = 'midnight';

        AP_Post::insert([
            'post_title' => 'Preview Probe',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Body under preview',
        ], $this->db);

        $query = new AP_Query([
            'post_type' => 'post',
            'posts_per_page' => 5,
        ], $this->db);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('agora-scheme-midnight', $html);
        $this->assertStringContainsString('agora-mode-dark', $html);
        $this->assertStringContainsString('data-agora-scheme-mode="dark"', $html);
        $this->assertStringContainsString('Preview Probe', $html);
        $this->assertStringNotContainsString('agora-scheme-parchment', $html);
        $this->assertSame('parchment', agora_get_stored_color_scheme($this->db));
        $this->assertSame('parchment', $this->storedColorSchemeValue());
    }

    public function testAgoraColorSchemeFilterCanOverrideResolvedSlug(): void
    {
        $this->assertTrue(agora_set_color_scheme('parchment', $this->db));
        $this->assertTrue(agora_set_visitor_color_preview(false, $this->db));

        ap_add_filter('agora_color_scheme', static function (string $slug): string {
            unset($slug);

            return 'charcoal';
        });

        $this->assertSame('charcoal', agora_get_color_scheme($this->db));
        $this->assertSame('parchment', agora_get_stored_color_scheme($this->db));
        $this->assertSame('parchment', $this->storedColorSchemeValue());
        $this->assertStringContainsString('agora-scheme-charcoal', agora_body_class($this->db));
    }

    public function testAgoraColorSchemeFilterReceivesResolvedPreviewSlugAndDoesNotWrite(): void
    {
        $this->assertTrue(agora_set_color_scheme('parchment', $this->db));
        $this->assertTrue(agora_set_visitor_color_preview(true, $this->db));
        $_GET[AGORA_COLOR_SCHEME_QUERY] = 'midnight';

        $seen = [];
        ap_add_filter('agora_color_scheme', static function (string $slug) use (&$seen): string {
            $seen[] = $slug;

            return $slug;
        });

        $this->assertSame('midnight', agora_get_color_scheme($this->db));
        $this->assertSame(['midnight'], $seen);
        $this->assertSame('parchment', agora_get_stored_color_scheme($this->db));
        $this->assertSame('parchment', $this->storedColorSchemeValue());
    }

    public function testAgoraColorSchemeFilterOverrideOfPreviewDoesNotWrite(): void
    {
        $this->assertTrue(agora_set_color_scheme('parchment', $this->db));
        $this->assertTrue(agora_set_visitor_color_preview(true, $this->db));
        $_COOKIE[AGORA_COLOR_SCHEME_COOKIE] = 'obsidian';

        ap_add_filter('agora_color_scheme', static function (string $slug): string {
            unset($slug);

            return 'charcoal';
        });

        $this->assertSame('charcoal', agora_get_color_scheme($this->db));
        $this->assertSame('parchment', agora_get_stored_color_scheme($this->db));
        $this->assertSame('parchment', $this->storedColorSchemeValue());
        $this->assertSame('obsidian', $_COOKIE[AGORA_COLOR_SCHEME_COOKIE]);
    }

    public function testInvalidAgoraColorSchemeFilterKeepsResolvedSlug(): void
    {
        $this->assertTrue(agora_set_color_scheme('parchment', $this->db));
        $this->assertTrue(agora_set_visitor_color_preview(true, $this->db));
        $_GET[AGORA_COLOR_SCHEME_QUERY] = 'midnight';

        ap_add_filter('agora_color_scheme', static function (string $slug): string {
            unset($slug);

            return 'neon-disco';
        });

        $this->assertSame('midnight', agora_get_color_scheme($this->db));
        $this->assertSame('parchment', $this->storedColorSchemeValue());

        $this->clearColorSchemePreviewRequest();
        $this->assertSame('parchment', agora_filter_color_scheme('parchment', $this->db));
        $this->assertSame('parchment', agora_get_color_scheme($this->db));
        $this->assertSame('parchment', $this->storedColorSchemeValue());
    }

    public function testStoredColorSchemeIgnoresFilterAndPreview(): void
    {
        $this->assertTrue(agora_set_color_scheme('parchment', $this->db));
        $this->assertTrue(agora_set_visitor_color_preview(true, $this->db));
        $_GET[AGORA_COLOR_SCHEME_QUERY] = 'midnight';
        ap_add_filter('agora_color_scheme', static function (string $slug): string {
            unset($slug);

            return 'charcoal';
        });

        $this->assertSame('parchment', agora_get_stored_color_scheme($this->db));
        $this->assertSame('charcoal', agora_get_color_scheme($this->db));
        $this->assertSame('parchment', $this->storedColorSchemeValue());
    }

    public function testPreviewHelpersNeverWriteColorSchemeOption(): void
    {
        $this->assertTrue(agora_set_color_scheme('cloud', $this->db));
        agora_refresh_preview_color_scheme_cookie('midnight');
        $this->assertSame('midnight', $_COOKIE[AGORA_COLOR_SCHEME_COOKIE]);
        $this->assertSame('cloud', $this->storedColorSchemeValue());

        foreach (
            [
                'agora_preview_scheme_from_query',
                'agora_preview_scheme_from_cookie',
                'agora_refresh_preview_color_scheme_cookie',
                'agora_get_stored_color_scheme',
                'agora_get_color_scheme',
                'agora_filter_color_scheme',
            ] as $name
        ) {
            $body = $this->agoraFunctionBody($name);
            $this->assertStringNotContainsString(
                'agora_set_color_scheme',
                $body,
                $name . ' must not persist the site scheme'
            );
            $this->assertStringNotContainsString(
                'agora_write_option',
                $body,
                $name . ' must not write options'
            );
        }

        $filterBody = $this->agoraFunctionBody('agora_filter_color_scheme');
        $this->assertStringContainsString("ap_apply_filters('agora_color_scheme'", $filterBody);
        $getBody = $this->agoraFunctionBody('agora_get_color_scheme');
        $this->assertStringContainsString('agora_filter_color_scheme', $getBody);
    }

    public function testVisitorPreviewControlAbsentByDefault(): void
    {
        $this->assertFalse(agora_visitor_color_preview_enabled($this->db));
        $this->assertFalse(agora_visitor_color_preview_control_enabled($this->db));
        $this->assertSame('', agora_get_visitor_color_preview_html($this->db));

        ob_start();
        agora_the_visitor_color_preview($this->db);
        $this->assertSame('', (string) ob_get_clean());

        $html = $this->renderPublicHome();
        $this->assertStringContainsString('site-header__inner', $html);
        $this->assertNoVisitorColorPreviewMarkup($html);
    }

    public function testVisitorPreviewControlAbsentWhenOptionOff(): void
    {
        $this->assertTrue(agora_set_color_scheme('parchment', $this->db));
        $this->assertTrue(agora_set_visitor_color_preview(false, $this->db));
        $_GET[AGORA_COLOR_SCHEME_QUERY] = 'midnight';
        $_COOKIE[AGORA_COLOR_SCHEME_COOKIE] = 'charcoal';

        $this->assertFalse(agora_visitor_color_preview_control_enabled($this->db));
        $this->assertSame('', agora_get_visitor_color_preview_html($this->db));

        ob_start();
        agora_the_visitor_color_preview($this->db);
        $this->assertSame('', (string) ob_get_clean());

        $html = $this->renderPublicHome();
        $this->assertNoVisitorColorPreviewMarkup($html);
        $this->assertStringContainsString('agora-scheme-parchment', $html);
        $this->assertStringNotContainsString('agora-scheme-midnight', $html);
        $this->assertSame('parchment', $this->storedColorSchemeValue());
    }

    public function testVisitorPreviewControlAbsentOnForumWhenOptionOff(): void
    {
        $this->assertTrue(agora_set_visitor_color_preview(false, $this->db));
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
        $this->assertNoVisitorColorPreviewMarkup($html);
    }

    public function testVisitorPreviewControlRendersSixGetLinksWhenOn(): void
    {
        $this->assertTrue(agora_set_color_scheme('parchment', $this->db));
        $this->assertTrue(agora_set_visitor_color_preview(true, $this->db));

        $this->assertTrue(agora_visitor_color_preview_control_enabled($this->db));
        $markup = agora_get_visitor_color_preview_html($this->db);
        $this->assertStringContainsString('agora-scheme-preview', $markup);
        $this->assertStringContainsString('aria-label="Preview color scheme"', $markup);
        $this->assertStringContainsString('is-current', $markup);
        $this->assertStringContainsString('agora-scheme-preview__swatch--parchment', $markup);

        foreach (['marble', 'parchment', 'cloud', 'obsidian', 'midnight', 'charcoal'] as $slug) {
            $this->assertStringContainsString('agora_scheme=' . $slug, $markup);
            $this->assertStringContainsString('agora-scheme-preview__swatch--' . $slug, $markup);
        }
        $this->assertSame(6, substr_count($markup, 'agora-scheme-preview__swatch--'));
        $this->assertStringNotContainsString('agorapress.extrovertednerd.com', $markup);

        $html = $this->renderPublicHome();
        $this->assertStringContainsString('agora-scheme-preview', $html);
        $this->assertStringContainsString('site-header__inner', $html);
        $accountPos = strpos($html, 'site-account');
        $previewPos = strpos($html, 'agora-scheme-preview');
        $mainPos = strpos($html, 'site-main');
        $this->assertNotFalse($accountPos);
        $this->assertNotFalse($previewPos);
        $this->assertNotFalse($mainPos);
        $this->assertGreaterThan($accountPos, $previewPos);
        $this->assertLessThan($mainPos, $previewPos);
        $this->assertStringContainsString('agora_scheme=midnight', $html);
        $this->assertSame('parchment', $this->storedColorSchemeValue());
    }

    public function testVisitorPreviewQueryAppliesMidnightWithoutWritingOption(): void
    {
        $this->assertTrue(agora_set_color_scheme('parchment', $this->db));
        $this->assertTrue(agora_set_visitor_color_preview(true, $this->db));
        $_GET[AGORA_COLOR_SCHEME_QUERY] = 'midnight';

        $markup = agora_get_visitor_color_preview_html($this->db);
        $this->assertStringContainsString('agora-scheme-preview__swatch--midnight is-current', $markup);
        $this->assertStringContainsString('aria-current="true"', $markup);
        $this->assertStringNotContainsString(
            'agora-scheme-preview__swatch--parchment is-current',
            $markup
        );

        $html = $this->renderPublicHome();
        $this->assertStringContainsString('agora-scheme-midnight', $html);
        $this->assertStringContainsString('agora-mode-dark', $html);
        $this->assertStringContainsString('agora-scheme-preview', $html);
        $this->assertSame('midnight', agora_get_color_scheme($this->db));
        $this->assertSame('parchment', agora_get_stored_color_scheme($this->db));
        $this->assertSame('parchment', $this->storedColorSchemeValue());
    }

    public function testVisitorPreviewCookieWinsOverSiteOptionInControl(): void
    {
        $this->assertTrue(agora_set_color_scheme('parchment', $this->db));
        $this->assertTrue(agora_set_visitor_color_preview(true, $this->db));
        $_COOKIE[AGORA_COLOR_SCHEME_COOKIE] = 'obsidian';

        $this->assertSame('obsidian', agora_get_color_scheme($this->db));
        $markup = agora_get_visitor_color_preview_html($this->db);
        $this->assertStringContainsString('agora-scheme-preview__swatch--obsidian is-current', $markup);
        $this->assertSame('parchment', $this->storedColorSchemeValue());
    }

    public function testVisitorPreviewInvalidSlugIgnoredInControl(): void
    {
        $this->assertTrue(agora_set_color_scheme('cloud', $this->db));
        $this->assertTrue(agora_set_visitor_color_preview(true, $this->db));
        $_GET[AGORA_COLOR_SCHEME_QUERY] = 'neon-disco';
        $_COOKIE[AGORA_COLOR_SCHEME_COOKIE] = 'midnight';

        $this->assertSame('midnight', agora_get_color_scheme($this->db));
        $markup = agora_get_visitor_color_preview_html($this->db);
        $this->assertStringContainsString('agora-scheme-preview__swatch--midnight is-current', $markup);
        $this->assertSame('', agora_visitor_color_preview_url('neon-disco'));
        $this->assertSame('cloud', $this->storedColorSchemeValue());
    }

    public function testVisitorPreviewUrlIsGetLinkWithoutHostname(): void
    {
        $prevUri = $_SERVER['REQUEST_URI'] ?? null;
        $prevGet = $_GET;
        try {
            $_SERVER['REQUEST_URI'] = '/blog/hello/?s=stone&agora_scheme=cloud';
            $_GET = [
                's' => 'stone',
                AGORA_COLOR_SCHEME_QUERY => 'cloud',
            ];
            $url = agora_visitor_color_preview_url('midnight');
            $this->assertSame('/blog/hello/?s=stone&agora_scheme=midnight', $url);
            $this->assertSame('', agora_visitor_color_preview_url('not-a-scheme'));
            $this->assertStringNotContainsString('://', $url);
            $this->assertStringNotContainsString('agorapress.extrovertednerd.com', $url);
        } finally {
            if ($prevUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $prevUri;
            }
            $_GET = $prevGet;
        }
    }

    public function testVisitorPreviewCookieUsesSameSiteLaxAndRootPath(): void
    {
        $body = $this->agoraFunctionBody('agora_refresh_preview_color_scheme_cookie');
        $this->assertStringContainsString("'path' => '/'", $body);
        $this->assertStringContainsString("'samesite' => 'Lax'", $body);
        $this->assertStringContainsString("'httponly' => false", $body);
        $this->assertStringNotContainsString('agora_set_color_scheme', $body);

        $header = (string) file_get_contents($this->root . '/ap-content/themes/agora/header.php');
        $accountPos = strpos($header, 'agora_the_account_indicator');
        $previewPos = strpos($header, 'agora_the_visitor_color_preview');
        $this->assertNotFalse($accountPos);
        $this->assertNotFalse($previewPos);
        $this->assertGreaterThan($accountPos, $previewPos);
        $this->assertStringContainsString('agora_get_color_schemes', $header);
        $this->assertStringContainsString('agora_visitor_color_preview_control_enabled', $header);
        $this->assertStringContainsString('site-header__inner', $header);
        $this->assertStringNotContainsString('agorapress.extrovertednerd.com', $header);
        $gatePos = strpos($header, 'agora_visitor_color_preview_control_enabled()');
        $callPos = strpos($header, 'agora_the_visitor_color_preview();');
        $this->assertNotFalse($gatePos);
        $this->assertNotFalse($callPos);
        $this->assertLessThan($callPos, $gatePos);
    }

    public function testThemeOptionsAdminFileExists(): void
    {
        $path = $this->root . '/ap-admin/theme-options.php';
        $this->assertFileIsReadable($path);
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('agora_color_scheme', $src);
        $this->assertStringContainsString('agora_set_color_scheme', $src);
        $this->assertStringContainsString('agora_get_stored_color_scheme', $src);
        $this->assertStringContainsString('agora_visitor_color_preview', $src);
        $this->assertStringContainsString('agora_set_visitor_color_preview', $src);
        $this->assertStringContainsString('Allow visitors to preview color schemes', $src);
        $this->assertStringContainsString('does not change this site', $src);
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
        $this->assertStringContainsString("'agora_visitor_color_preview'", $installer);
        $this->assertMatchesRegularExpression(
            "/'agora_visitor_color_preview'\\s*=>\\s*'0'/",
            $installer
        );
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
        // SPEC B2 — two-pane post layout (author left, body/actions right).
        $this->assertStringContainsString('.ap-forum-post--two-pane', $css);
        $this->assertStringContainsString('.ap-forum-post__main', $css);
        $this->assertStringContainsString('.ap-forum-post__author', $css);
        $this->assertStringContainsString('grid-template-areas: "author main"', $css);
        $this->assertStringContainsString('--ap-forum-author-col', $css);
        $this->assertStringContainsString('.ap-forum-post__head-start', $css);
        $this->assertStringContainsString('.ap-forum-post__actions', $css);
        $this->assertStringContainsString('.ap-forum-post__joined', $css);
        $this->assertStringContainsString('.ap-forum-post__location', $css);
        // SPEC B2 — signature row under post body.
        $this->assertStringContainsString('.ap-forum-post__signature', $css);
        $this->assertStringContainsString('.ap-forum-post__signature-body', $css);
        // SPEC B2 — bottom-right “Top” control.
        $this->assertStringContainsString('.ap-forum-post__foot', $css);
        $this->assertStringContainsString('.ap-forum-post__top', $css);
        // SPEC B1 — first unread jump above OP.
        $this->assertStringContainsString('.ap-forum-first-unread', $css);
        $this->assertStringContainsString('.ap-forum-first-unread-wrap', $css);
        $this->assertStringContainsString('.ap-pagination', $css);
        $this->assertStringContainsString('.ap-breadcrumbs', $css);
        $this->assertStringContainsString('--ap-on-accent', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('focus-visible', $css);
        $this->assertStringContainsString('skip-link', $css);
        $this->assertMatchesRegularExpression('/@media\s*\(\s*max-width:/', $css);
        // Theme stylesheet version must stay in lockstep with AGORA_THEME_VERSION.
        $this->assertStringContainsString('Version: 0.3.9', $css);
        $functions = (string) file_get_contents($this->root . '/ap-content/themes/agora/functions.php');
        $this->assertStringContainsString("AGORA_THEME_VERSION = '0.3.9'", $functions);
        // Desktop shell: blog, wide, and forum pages share one max width.
        $this->assertStringContainsString('--ap-max-wide: var(--ap-max)', $css);
        $this->assertStringContainsString('--ap-max-forum: var(--ap-max)', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/body\.agora-forum[^{]*\{[^}]*width:\s*min\(\s*var\(--ap-max-forum\)/',
            $css
        );
        // Phase 5 a11y: button/focus hooks + unread contrast without opacity-on-read.
        $this->assertStringContainsString('.ap-btn:focus-visible', $css);
        $this->assertStringContainsString('.ap-btn--ghost:focus-visible', $css);
        $this->assertStringContainsString('.ap-forum-like:focus-visible', $css);
        $this->assertStringContainsString('.ap-forum-post__top:focus-visible', $css);
        $this->assertStringContainsString('--ap-forum-unread-bar-width', $css);
        $this->assertStringContainsString('Do not dim whole rows with opacity', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/\.ap-forum-row--read\s*\{[^}]*opacity\s*:/',
            $css
        );
        // SPEC A1–A4 board hooks (theme-local CSS; core only emits class names).
        $this->assertStringContainsString('.ap-forum-cat-header', $css);
        $this->assertStringContainsString('grid-column: 1 / span 2', $css);
        $this->assertStringContainsString('--ap-forum-icon-col', $css);
        $this->assertStringContainsString('--ap-forum-stat-col', $css);
        $this->assertStringContainsString('--ap-forum-last-col', $css);
        $this->assertStringContainsString('minmax(0, 1fr)', $css);
        $this->assertStringContainsString('.ap-forum-row--unread', $css);
        $this->assertStringContainsString('.ap-forum-row--read', $css);
        $this->assertStringContainsString('.ap-forum-icon--unread', $css);
        $this->assertStringContainsString('.ap-forum-icon--read', $css);
        $this->assertStringContainsString('.ap-forum-icon--sticky.ap-forum-icon--unread', $css);
        $this->assertStringContainsString('.ap-forum-last-post__title', $css);
        $this->assertStringContainsString('.ap-forum-last-post__author', $css);
        $this->assertStringContainsString('.ap-forum-last-post__time', $css);
        $this->assertStringContainsString(':not(.ap-forum-row--unread)', $css);
        $this->assertStringContainsString('.ap-forum-row--locked.ap-forum-row--unread', $css);
        $this->assertStringContainsString('overflow-wrap: anywhere', $css);
        $this->assertStringContainsString('--ap-field-bg', $css);
        $this->assertStringContainsString('--ap-surface:', $css);
        $this->assertStringContainsString('.ap-comment-form input', $css);
        $this->assertStringContainsString('color-scheme: inherit', $css);
        $this->assertStringContainsString('.site-account', $css);
        $this->assertStringContainsString('.site-account__welcome', $css);
        $this->assertStringContainsString('.site-account__logout', $css);
        $this->assertStringContainsString('.site-account__login', $css);
        $this->assertStringContainsString('.site-account__register', $css);
        $this->assertStringContainsString('.site-account--guest', $css);
        $this->assertStringContainsString('.agora-scheme-preview', $css);
        $this->assertStringContainsString('.agora-scheme-preview__swatch', $css);
        $this->assertStringContainsString('.agora-scheme-preview__swatch.is-current', $css);
        $this->assertStringContainsString('.ap-meta-categories', $css);
        $this->assertStringContainsString('.ap-entry__footer', $css);
        $this->assertStringContainsString('.ap-entry__footer-label', $css);
    }

    public function testGuestAuthLinksLoginOnlyWhenRegistrationClosed(): void
    {
        $this->assertTrue(function_exists('agora_get_guest_auth_links'));
        $this->assertTrue(function_exists('agora_the_account_indicator'));
        $this->assertNull(agora_get_account_indicator($this->db));

        // Default: registration closed → Log in only.
        AP_Options::update('users_can_register', '0', $this->db);
        $guest = agora_get_guest_auth_links($this->db);
        $this->assertIsArray($guest);
        $this->assertStringContainsString('login.php', $guest['login_url']);
        $this->assertFalse($guest['can_register']);
        $this->assertSame('', $guest['register_url']);

        ob_start();
        agora_the_account_indicator($this->db);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('site-account--guest', $html);
        $this->assertStringContainsString('site-account__login', $html);
        $this->assertStringContainsString('Log in', $html);
        $this->assertStringNotContainsString('site-account__register', $html);
        $this->assertStringNotContainsString('Register', $html);
        $this->assertStringNotContainsString('Welcome,', $html);
    }

    public function testGuestAuthLinksIncludeRegisterWhenOpen(): void
    {
        AP_Options::update('users_can_register', '1', $this->db);

        $guest = agora_get_guest_auth_links($this->db);
        $this->assertIsArray($guest);
        $this->assertTrue($guest['can_register']);
        $this->assertStringContainsString('login.php', $guest['login_url']);
        $this->assertStringContainsString('action=register', $guest['register_url']);

        ob_start();
        agora_the_account_indicator($this->db);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('site-account--guest', $html);
        $this->assertStringContainsString('Log in', $html);
        $this->assertStringContainsString('Register', $html);
        $this->assertStringContainsString('site-account__register', $html);
        $this->assertStringContainsString('action=register', $html);
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

    public function testGuestRenderShowsLoginAndOptionalRegister(): void
    {
        AP_Options::update('users_can_register', '0', $this->db);
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
        $htmlClosed = (string) ob_get_clean();

        $this->assertStringContainsString('site-account--guest', $htmlClosed);
        $this->assertStringContainsString('Log in', $htmlClosed);
        $this->assertStringContainsString('login.php', $htmlClosed);
        $this->assertStringNotContainsString('Register', $htmlClosed);
        $this->assertStringNotContainsString('Welcome,', $htmlClosed);

        AP_Options::update('users_can_register', '1', $this->db);
        ob_start();
        AP_Theme::render($query, $this->db);
        $htmlOpen = (string) ob_get_clean();

        $this->assertStringContainsString('Log in', $htmlOpen);
        $this->assertStringContainsString('Register', $htmlOpen);
        $this->assertStringContainsString('action=register', $htmlOpen);
        $this->assertStringNotContainsString('Welcome,', $htmlOpen);
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
        $this->assertNoVisitorColorPreviewMarkup($html);
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
        $this->assertStringContainsString('ap-forum-post--two-pane', $html);
        $this->assertStringContainsString('ap-forum-post__author', $html);
        $this->assertStringContainsString('ap-forum-post__main', $html);
        $this->assertStringContainsString('ap-forum-post__body', $html);
        $this->assertStringContainsString('Hello **world**', $html);
        // SPEC B2 — Top of page control + topic top anchor.
        $this->assertStringContainsString('id="ap-topic-top"', $html);
        $this->assertStringContainsString('ap-forum-post__foot', $html);
        $this->assertStringContainsString('ap-forum-post__top', $html);
        $this->assertStringContainsString('href="#ap-topic-top"', $html);
        $this->assertStringContainsString('aria-label="Back to top of topic"', $html);
        $this->assertMatchesRegularExpression(
            '/class="[^"]*ap-forum-post__top[^"]*"[^>]*>\s*Top\s*<\/a>/',
            $html
        );
    }

    public function testTopicPostActionButtonsHaveAccessibleLabels(): void
    {
        if (function_exists('ap_add_filter')) {
            ap_add_filter('agora_topic_posts_data', static function (array $data, int $topicId): array {
                if ($topicId !== 46) {
                    return $data;
                }

                return [
                    [
                        'id' => 11,
                        'author' => 'Carol',
                        'author_id' => 5,
                        'date' => '2026-08-01 12:00:00',
                        'content' => 'Action labels body',
                        'role' => 'Member',
                        'number' => 1,
                        'can_quote' => true,
                        'can_edit' => true,
                        'can_delete' => true,
                        'can_like' => true,
                        'like_count' => 3,
                        'liked_by_me' => false,
                        'author_stats' => [
                            'forum_posts' => 2,
                            'forum_likes_given' => 1,
                            'forum_likes_received' => 3,
                        ],
                    ],
                ];
            }, 10, 2);
        }

        $query = new AP_Query([
            'ap_forum_view' => 'topic',
            'topic_id' => 46,
            'topic_title' => 'Action labels topic',
            'forum_name' => 'General',
            'posts_per_page' => 1,
        ], $this->db);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('aria-label="Post actions"', $html);
        $this->assertStringContainsString('aria-label="Quote post #1"', $html);
        $this->assertStringContainsString('aria-label="Edit post #1"', $html);
        $this->assertStringContainsString('aria-label="Delete post #1"', $html);
        $this->assertStringContainsString('aria-label="Like post #1 (3 likes)"', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringContainsString('aria-label="Back to top of topic"', $html);
    }

    public function testTopicAuthorPaneRendersJoinedAndLocation(): void
    {
        agora_set_color_scheme('cloud', $this->db);
        if (function_exists('ap_add_filter')) {
            ap_add_filter('agora_topic_posts_data', static function (array $data, int $topicId): array {
                if ($topicId !== 43) {
                    return $data;
                }

                return [
                    [
                        'id' => 7,
                        'author' => 'Alice',
                        'author_id' => 3,
                        'author_url' => '/author/alice/',
                        'date' => '2026-08-01 12:00:00',
                        'content' => 'Pane body',
                        'role' => 'Member',
                        'number' => 1,
                        'joined' => '2024-03-15 09:30:00',
                        'location' => 'Athens, GR',
                        'avatar_html' => '',
                        'author_stats' => [
                            'forum_posts' => 12,
                            'forum_likes_given' => 4,
                            'forum_likes_received' => 9,
                        ],
                    ],
                ];
            }, 10, 2);
        }

        $query = new AP_Query([
            'ap_forum_view' => 'topic',
            'topic_id' => 43,
            'topic_title' => 'Author pane topic',
            'forum_name' => 'General',
            'posts_per_page' => 1,
        ], $this->db);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('ap-forum-post__author', $html);
        $this->assertStringContainsString('Alice', $html);
        $this->assertStringContainsString('Member', $html);
        $this->assertStringContainsString('Posts', $html);
        $this->assertStringContainsString('Likes given', $html);
        $this->assertStringContainsString('Likes received', $html);
        $this->assertStringContainsString('Joined', $html);
        $this->assertStringContainsString('ap-forum-post__joined', $html);
        $this->assertStringContainsString('Mar 15, 2024', $html);
        $this->assertStringContainsString('Location', $html);
        $this->assertStringContainsString('ap-forum-post__location', $html);
        $this->assertStringContainsString('Athens, GR', $html);
        $this->assertStringContainsString('ap-forum-post__stat--posts', $html);
    }

    public function testTopicPostRendersSignatureWhenPresent(): void
    {
        agora_set_color_scheme('cloud', $this->db);
        if (function_exists('ap_add_filter')) {
            ap_add_filter('agora_topic_posts_data', static function (array $data, int $topicId): array {
                if ($topicId !== 44) {
                    return $data;
                }

                return [
                    [
                        'id' => 8,
                        'author' => 'Alice',
                        'author_id' => 3,
                        'date' => '2026-08-01 12:00:00',
                        'content' => 'Body with sig',
                        'role' => 'Member',
                        'number' => 1,
                        'signature' => 'My custom sig line',
                        'signature_html' => '<p>My custom sig line</p>',
                        'author_stats' => [
                            'forum_posts' => 1,
                            'forum_likes_given' => 0,
                            'forum_likes_received' => 0,
                        ],
                    ],
                ];
            }, 10, 2);
        }

        $query = new AP_Query([
            'ap_forum_view' => 'topic',
            'topic_id' => 44,
            'topic_title' => 'Signature topic',
            'forum_name' => 'General',
            'posts_per_page' => 1,
        ], $this->db);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('ap-forum-post__signature', $html);
        $this->assertStringContainsString('ap-forum-post__signature-body', $html);
        $this->assertStringContainsString('My custom sig line', $html);
        $this->assertStringContainsString('aria-label="Signature"', $html);
    }

    public function testTopicPostOmitsSignatureWhenEmpty(): void
    {
        agora_set_color_scheme('cloud', $this->db);
        if (function_exists('ap_add_filter')) {
            ap_add_filter('agora_topic_posts_data', static function (array $data, int $topicId): array {
                if ($topicId !== 45) {
                    return $data;
                }

                return [
                    [
                        'id' => 9,
                        'author' => 'Bob',
                        'author_id' => 4,
                        'date' => '2026-08-01 12:00:00',
                        'content' => 'No sig body',
                        'number' => 1,
                        'signature' => '',
                        'signature_html' => '',
                    ],
                ];
            }, 10, 2);
        }

        $query = new AP_Query([
            'ap_forum_view' => 'topic',
            'topic_id' => 45,
            'topic_title' => 'No signature topic',
            'forum_name' => 'General',
            'posts_per_page' => 1,
        ], $this->db);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('No sig body', $html);
        $this->assertStringNotContainsString('ap-forum-post__signature', $html);
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

    public function testBlogListAndSinglePostShowLinkedCategories(): void
    {
        $cat = AP_Taxonomy::insertTerm('News Desk', 'category', ['slug' => 'news-desk'], $this->db);
        $this->assertIsArray($cat);
        $catId = (int) $cat['term_id'];
        $this->assertGreaterThan(0, $catId);

        $postId = AP_Post::insert([
            'post_title' => 'Categorized Story',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Story body for the single view.',
            'post_name' => 'categorized-story',
        ], $this->db);
        $this->assertGreaterThan(0, $postId);
        AP_Taxonomy::setObjectTerms($postId, [$catId], 'category', false, $this->db);

        $list = new AP_Query([
            'post_type' => 'post',
            'posts_per_page' => 5,
        ], $this->db);
        ap_set_query($list);

        ob_start();
        AP_Theme::render($list, $this->db);
        $listHtml = (string) ob_get_clean();

        $this->assertStringContainsString('Categorized Story', $listHtml);
        $this->assertStringContainsString('News Desk', $listHtml);
        $this->assertStringContainsString('ap-meta-categories', $listHtml);
        $this->assertStringContainsString('rel="tag"', $listHtml);
        $this->assertTrue(
            str_contains($listHtml, 'news-desk') || str_contains($listHtml, '?cat=' . $catId),
            'List category link should point at the term archive'
        );
        $this->assertStringNotContainsString('ap-entry__footer', $listHtml);

        $single = new AP_Query(['p' => $postId], $this->db);
        $this->assertTrue($single->is_single);
        ap_set_query($single);

        ob_start();
        AP_Theme::render($single, $this->db);
        $singleHtml = (string) ob_get_clean();

        $this->assertStringContainsString('Categorized Story', $singleHtml);
        $this->assertStringContainsString('News Desk', $singleHtml);
        $this->assertStringContainsString('ap-meta-categories', $singleHtml);
        $this->assertStringContainsString('ap-entry__footer', $singleHtml);
        $this->assertStringContainsString('Posted in', $singleHtml);
        $this->assertTrue(
            str_contains($singleHtml, 'news-desk') || str_contains($singleHtml, '?cat=' . $catId),
            'Single category link should point at the term archive'
        );
    }

    public function testPageTemplateDoesNotListPostCategories(): void
    {
        $cat = AP_Taxonomy::insertTerm('Should Not Appear', 'category', ['slug' => 'should-not-appear'], $this->db);
        $this->assertIsArray($cat);

        $pageId = AP_Post::insert([
            'post_title' => 'About Categories Page',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => 'Page body',
            'post_name' => 'about-categories-page',
        ], $this->db);
        $this->assertGreaterThan(0, $pageId);

        $query = new AP_Query(['page_id' => $pageId], $this->db);
        $this->assertTrue($query->is_page);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('About Categories Page', $html);
        $this->assertStringNotContainsString('Should Not Appear', $html);
        $this->assertStringNotContainsString('ap-meta-categories', $html);
        $this->assertStringNotContainsString('ap-entry__footer', $html);
    }

    public function testEntryFooterSkipsEmptyCategoryNames(): void
    {
        $named = $this->insertCategoryTerm('News Desk', 'news-desk-skip-empty');
        $blank = $this->insertCategoryTerm('', 'blank-agora-category');
        $spaces = $this->insertCategoryTerm('   ', 'whitespace-agora-category');

        $postId = AP_Post::insert([
            'post_title' => 'Mixed Category Names',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Story body.',
            'post_name' => 'mixed-category-names',
        ], $this->db);
        $this->assertGreaterThan(0, $postId);
        AP_Taxonomy::setObjectTerms(
            $postId,
            [$named, $blank, $spaces],
            'category',
            false,
            $this->db
        );

        $post = AP_Post::get($postId, $this->db);
        $this->assertInstanceOf(AP_Post::class, $post);
        $GLOBALS['ap_post'] = $post;

        ob_start();
        agora_the_entry_footer();
        $footer = (string) ob_get_clean();
        $this->assertStringContainsString('ap-entry__footer', $footer);
        $this->assertStringContainsString('Posted in', $footer);
        $this->assertStringContainsString('News Desk', $footer);
        $this->assertStringNotContainsString(', ,', $footer);
        $this->assertStringNotContainsString('?cat=' . $blank, $footer);
        $this->assertStringNotContainsString('blank-agora-category', $footer);
        $this->assertStringNotContainsString('whitespace-agora-category', $footer);

        ob_start();
        agora_the_entry_meta();
        $meta = (string) ob_get_clean();
        $this->assertStringContainsString('ap-meta-categories', $meta);
        $this->assertStringContainsString('News Desk', $meta);
        $this->assertStringNotContainsString(', ,', $meta);
        $this->assertStringNotContainsString('?cat=' . $blank, $meta);
    }

    public function testRenderedHtmlOmitsEmptyNameCategoryTerms(): void
    {
        $named = $this->insertCategoryTerm('News Desk', 'news-desk-html-omit');
        $blank = $this->insertCategoryTerm('', 'blank-html-omit');
        $spaces = $this->insertCategoryTerm('   ', 'whitespace-html-omit');

        $postId = AP_Post::insert([
            'post_title' => 'Mixed Names HTML Story',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Story body for mixed empty-name HTML.',
            'post_name' => 'mixed-names-html-story',
        ], $this->db);
        $this->assertGreaterThan(0, $postId);
        AP_Taxonomy::setObjectTerms(
            $postId,
            [$named, $blank, $spaces],
            'category',
            false,
            $this->db
        );

        $list = new AP_Query([
            'post_type' => 'post',
            'posts_per_page' => 5,
        ], $this->db);
        ap_set_query($list);

        ob_start();
        AP_Theme::render($list, $this->db);
        $listHtml = (string) ob_get_clean();

        $this->assertStringContainsString('Mixed Names HTML Story', $listHtml);
        $this->assertStringContainsString('News Desk', $listHtml);
        $this->assertStringContainsString('ap-meta-categories', $listHtml);
        $this->assertStringContainsString('Posted in', $listHtml);
        $this->assertStringNotContainsString(', ,', $listHtml);
        $this->assertStringNotContainsString('?cat=' . $blank, $listHtml);
        $this->assertStringNotContainsString('?cat=' . $spaces, $listHtml);
        $this->assertStringNotContainsString('blank-html-omit', $listHtml);
        $this->assertStringNotContainsString('whitespace-html-omit', $listHtml);

        $single = new AP_Query(['p' => $postId], $this->db);
        $this->assertTrue($single->is_single);
        ap_set_query($single);

        ob_start();
        AP_Theme::render($single, $this->db);
        $singleHtml = (string) ob_get_clean();

        $this->assertStringContainsString('Mixed Names HTML Story', $singleHtml);
        $this->assertStringContainsString('News Desk', $singleHtml);
        $this->assertStringContainsString('ap-meta-categories', $singleHtml);
        $this->assertStringContainsString('ap-entry__footer', $singleHtml);
        $this->assertStringContainsString('Posted in', $singleHtml);
        $this->assertStringNotContainsString(', ,', $singleHtml);
        $this->assertStringNotContainsString('?cat=' . $blank, $singleHtml);
        $this->assertStringNotContainsString('?cat=' . $spaces, $singleHtml);
        $this->assertStringNotContainsString('blank-html-omit', $singleHtml);
        $this->assertStringNotContainsString('whitespace-html-omit', $singleHtml);
    }

    public function testEntryFooterOmitsPostedInWhenAllCategoryNamesEmpty(): void
    {
        $blank = $this->insertCategoryTerm('', 'agora-all-blank-category');
        $spaces = $this->insertCategoryTerm(" \t ", 'agora-all-whitespace-category');

        $postId = AP_Post::insert([
            'post_title' => 'Nameless Categories Story',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Story body for nameless terms.',
            'post_name' => 'nameless-categories-story',
        ], $this->db);
        $this->assertGreaterThan(0, $postId);
        AP_Taxonomy::setObjectTerms($postId, [$blank, $spaces], 'category', false, $this->db);

        $post = AP_Post::get($postId, $this->db);
        $this->assertInstanceOf(AP_Post::class, $post);
        $GLOBALS['ap_post'] = $post;

        ob_start();
        agora_the_entry_footer();
        $this->assertSame('', (string) ob_get_clean());

        ob_start();
        agora_the_entry_meta();
        $meta = (string) ob_get_clean();
        $this->assertStringNotContainsString('Posted in', $meta);
        $this->assertStringNotContainsString('ap-meta-categories', $meta);
        $this->assertStringNotContainsString(', ,', $meta);

        $single = new AP_Query(['p' => $postId], $this->db);
        $this->assertTrue($single->is_single);
        ap_set_query($single);

        ob_start();
        AP_Theme::render($single, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Nameless Categories Story', $html);
        $this->assertStringNotContainsString('Posted in', $html);
        $this->assertStringNotContainsString('ap-meta-categories', $html);
        $this->assertStringNotContainsString('ap-entry__footer', $html);
        $this->assertStringNotContainsString(', ,', $html);
        $this->assertStringNotContainsString('agora-all-blank-category', $html);

        $list = new AP_Query([
            'post_type' => 'post',
            'posts_per_page' => 5,
        ], $this->db);
        ap_set_query($list);

        ob_start();
        AP_Theme::render($list, $this->db);
        $listHtml = (string) ob_get_clean();

        $this->assertStringContainsString('Nameless Categories Story', $listHtml);
        $this->assertStringNotContainsString('Posted in', $listHtml);
        $this->assertStringNotContainsString('ap-meta-categories', $listHtml);
        $this->assertStringNotContainsString('ap-entry__footer', $listHtml);
        $this->assertStringNotContainsString(', ,', $listHtml);
        $this->assertStringNotContainsString('agora-all-blank-category', $listHtml);
    }

    private function insertCategoryTerm(string $name, string $slug): int
    {
        $n = $this->db->insert('terms', [
            'name' => $name,
            'slug' => $slug,
            'term_group' => 0,
        ]);
        $this->assertSame(1, $n);
        $termId = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $termId);

        $n = $this->db->insert('term_taxonomy', [
            'term_id' => $termId,
            'taxonomy' => 'category',
            'description' => '',
            'parent' => 0,
            'count' => 0,
        ]);
        $this->assertSame(1, $n);

        return $termId;
    }
}
