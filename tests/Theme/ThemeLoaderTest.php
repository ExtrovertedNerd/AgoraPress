<?php

/**
 * Tests for AP_Theme — template loader and hierarchy.
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
use AP_Theme;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Theme::class)]
final class ThemeLoaderTest extends TestCase
{
    private string $root;

    private string $tempThemes;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/class-ap-nav-menu.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-theme.php';
        require_once $this->root . '/ap-includes/class-ap-assets.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-includes/template-tags.php';

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Post::resetRegistry();
        AP_Theme::reset();
        if (class_exists('AP_Assets', false)) {
            \AP_Assets::reset();
        }
        AP_Nav_Menu::reset();
        if (class_exists('AP_Options', false)) {
            AP_Options::flushCache();
        }
        if (class_exists('AP_Rewrite', false)) {
            AP_Rewrite::resetCache();
        }
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
        $this->db->insert('options', [
            'option_name' => 'stylesheet',
            'option_value' => 'agora',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'template',
            'option_value' => 'agora',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'blogname',
            'option_value' => 'Theme Test Site',
            'autoload' => 'yes',
        ]);

        $this->tempThemes = sys_get_temp_dir() . '/ap-themes-' . uniqid('', true);
        $this->assertTrue(mkdir($this->tempThemes, 0700, true));
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
        AP_Theme::reset();
        AP_Nav_Menu::reset();
        if (class_exists('AP_Options', false)) {
            AP_Options::flushCache();
        }
        if (class_exists('AP_Rewrite', false)) {
            AP_Rewrite::resetCache();
        }
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post'], $GLOBALS['apdb']);
        $this->removeDir($this->tempThemes);
    }

    public function testDefaultThemeShipsWithStyleCssAndIndex(): void
    {
        $agora = $this->root . '/ap-content/themes/agora';
        $this->assertDirectoryExists($agora);
        $this->assertFileIsReadable($agora . '/style.css');
        $this->assertFileIsReadable($agora . '/index.php');
        $this->assertFileIsReadable($agora . '/single.php');
        $this->assertFileIsReadable($agora . '/page.php');
        $this->assertFileIsReadable($agora . '/404.php');

        $headers = AP_Theme::parseStyleCss($agora . '/style.css');
        $this->assertSame('Agora', $headers['Theme Name'] ?? null);
    }

    public function testListThemesFindsAgora(): void
    {
        // Use real themes root.
        AP_Theme::setThemesRootOverride(null);
        $themes = AP_Theme::listThemes();
        $this->assertArrayHasKey('agora', $themes);
        $this->assertSame('Agora', $themes['agora']['Theme Name']);
    }

    public function testHierarchyHomeSinglePageSearch404(): void
    {
        AP_Theme::setThemesRootOverride($this->root . '/ap-content/themes');
        AP_Theme::setActiveOverride('agora', 'agora');

        $home = new AP_Query([
            'post_type' => 'post',
            'posts_per_page' => 5,
        ], $this->db);
        $this->assertTrue($home->is_home);
        // Latest posts on the front is both is_home and is_front_page → front-page.php first.
        $this->assertTrue($home->is_front_page);
        $h = AP_Theme::getHierarchy($home, $this->db);
        $this->assertSame('front-page.php', $h[0]);
        $this->assertContains('home.php', $h);
        $this->assertSame('index.php', $h[array_key_last($h)]);

        $postId = AP_Post::insert([
            'post_title' => 'Hello',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_name' => 'hello',
            'post_content' => 'Body',
        ], $this->db);
        $single = new AP_Query(['p' => $postId], $this->db);
        $this->assertTrue($single->is_single);
        $sh = AP_Theme::getHierarchy($single, $this->db);
        $this->assertContains('single-post-hello.php', $sh);
        $this->assertContains('single.php', $sh);
        $this->assertContains('singular.php', $sh);

        $pageId = AP_Post::insert([
            'post_title' => 'About',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_name' => 'about',
            'post_content' => 'About body',
            'page_template' => 'templates/full-width.php',
        ], $this->db);
        $page = new AP_Query(['page_id' => $pageId], $this->db);
        $this->assertTrue($page->is_page);
        $ph = AP_Theme::getHierarchy($page, $this->db);
        $this->assertSame('templates/full-width.php', $ph[0]);
        $this->assertContains('page-about.php', $ph);
        $this->assertContains('page.php', $ph);

        $search = new AP_Query(['s' => 'hello'], $this->db);
        $this->assertTrue($search->is_search);
        $this->assertSame('search.php', AP_Theme::getHierarchy($search, $this->db)[0]);

        $missing = new AP_Query(['p' => 999999], $this->db);
        $this->assertTrue($missing->is_404);
        $this->assertSame('404.php', AP_Theme::getHierarchy($missing, $this->db)[0]);
    }

    public function testHierarchyCategoryTagAuthor(): void
    {
        AP_Theme::setThemesRootOverride($this->root . '/ap-content/themes');

        $cat = new AP_Query(['category_name' => 'news', 'post_type' => 'post'], $this->db);
        $this->assertTrue($cat->is_category);
        $ch = AP_Theme::getHierarchy($cat, $this->db);
        $this->assertSame('category-news.php', $ch[0]);
        $this->assertContains('category.php', $ch);
        $this->assertContains('archive.php', $ch);

        $tag = new AP_Query(['tag' => 'php', 'post_type' => 'post'], $this->db);
        $this->assertTrue($tag->is_tag);
        $th = AP_Theme::getHierarchy($tag, $this->db);
        $this->assertSame('tag-php.php', $th[0]);
        $this->assertContains('tag.php', $th);

        $author = new AP_Query(['author_name' => 'alice', 'post_type' => 'post'], $this->db);
        // author_name without matching user yields empty posts; flags set at parse.
        $this->assertTrue($author->is_author);
        $ah = AP_Theme::getHierarchy($author, $this->db);
        $this->assertContains('author-alice.php', $ah);
        $this->assertContains('author.php', $ah);
        $this->assertContains('archive.php', $ah);
    }

    public function testLocateTemplateChildThenParent(): void
    {
        $parent = $this->tempThemes . '/parent-theme';
        $child = $this->tempThemes . '/child-theme';
        $this->assertTrue(mkdir($parent, 0700, true));
        $this->assertTrue(mkdir($child, 0700, true));

        file_put_contents($parent . '/style.css', "/*\nTheme Name: Parent Theme\n*/\n");
        file_put_contents($child . '/style.css', "/*\nTheme Name: Child Theme\nTemplate: parent-theme\n*/\n");
        file_put_contents($parent . '/index.php', '<?php echo "PARENT_INDEX";');
        file_put_contents($parent . '/single.php', '<?php echo "PARENT_SINGLE";');
        file_put_contents($child . '/single.php', '<?php echo "CHILD_SINGLE";');
        // Child has no index.php — falls through to parent.

        AP_Theme::setThemesRootOverride($this->tempThemes);
        AP_Theme::setActiveOverride('child-theme', 'parent-theme');

        $this->assertSame(
            $child . '/single.php',
            AP_Theme::locateTemplate(['single.php'], false, true, [], $this->db)
        );
        $this->assertSame(
            $parent . '/index.php',
            AP_Theme::locateTemplate(['index.php'], false, true, [], $this->db)
        );
        $this->assertSame(
            $child . '/single.php',
            AP_Theme::locateTemplate(['missing.php', 'single.php'], false, true, [], $this->db)
        );
        $this->assertSame('', AP_Theme::locateTemplate(['nope.php'], false, true, [], $this->db));
    }

    public function testLocateRejectsPathTraversal(): void
    {
        AP_Theme::setThemesRootOverride($this->root . '/ap-content/themes');
        AP_Theme::setActiveOverride('agora', 'agora');

        $this->assertSame(
            '',
            AP_Theme::locateTemplate(['../../../ap-includes/version.php'], false, true, [], $this->db)
        );
    }

    public function testGetPageTemplatesDiscoversFullWidth(): void
    {
        AP_Theme::setThemesRootOverride($this->root . '/ap-content/themes');
        AP_Theme::setActiveOverride('agora', 'agora');

        $templates = AP_Theme::getPageTemplates($this->db);
        $this->assertArrayHasKey('templates/full-width.php', $templates);
        $this->assertSame('Full Width', $templates['templates/full-width.php']);
    }

    public function testSetActivePersistsOptions(): void
    {
        AP_Theme::setThemesRootOverride($this->root . '/ap-content/themes');
        $this->assertTrue(AP_Theme::setActive('agora', 'agora', $this->db));
        $this->assertSame('agora', AP_Theme::getStylesheet($this->db));
        $this->assertSame('agora', AP_Theme::getTemplate($this->db));
        $this->assertFalse(AP_Theme::setActive('does-not-exist', null, $this->db));
    }

    public function testRenderHomeOutputsThemeMarkup(): void
    {
        AP_Theme::setThemesRootOverride($this->root . '/ap-content/themes');
        AP_Theme::setActiveOverride('agora', 'agora');
        $GLOBALS['apdb'] = $this->db;

        AP_Post::insert([
            'post_title' => 'Public Post',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Hello public world',
        ], $this->db);

        $query = new AP_Query([
            'post_type' => 'post',
            'posts_per_page' => 10,
        ], $this->db);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Public Post', $html);
        $this->assertStringContainsString('Hello public world', $html);
        $this->assertStringContainsString('agora-theme', $html);
        $this->assertStringContainsString('agora-scheme-marble', $html);
        $this->assertStringContainsString('Theme Test Site', $html);
        $this->assertStringContainsString('style.css', $html);
    }

    public function testRender404Uses404Template(): void
    {
        AP_Theme::setThemesRootOverride($this->root . '/ap-content/themes');
        AP_Theme::setActiveOverride('agora', 'agora');

        $query = new AP_Query(['p' => 999999], $this->db);
        $this->assertTrue($query->is_404);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Page not found', $html);
    }

    public function testProceduralHelpers(): void
    {
        AP_Theme::setThemesRootOverride($this->root . '/ap-content/themes');
        AP_Theme::setActiveOverride('agora', 'agora');

        $this->assertSame('agora', ap_get_stylesheet($this->db));
        $this->assertSame('agora', ap_get_template($this->db));
        $this->assertStringEndsWith('/agora', ap_get_stylesheet_directory($this->db));
        $this->assertStringContainsString('/agora', ap_get_stylesheet_uri($this->db));

        $q = new AP_Query(['s' => 'x'], $this->db);
        $this->assertSame('search.php', ap_get_template_hierarchy($q, $this->db)[0]);

        $path = ap_locate_template(['404.php'], false, true, [], $this->db);
        $this->assertNotSame('', $path);
        $this->assertFileExists($path);

        $themes = ap_get_themes();
        $this->assertArrayHasKey('agora', $themes);
    }

    public function testSetupLoadsThemeFunctions(): void
    {
        AP_Theme::setThemesRootOverride($this->root . '/ap-content/themes');
        AP_Theme::setActiveOverride('agora', 'agora');
        AP_Theme::setup($this->db);

        $this->assertTrue(function_exists('agora_site_name'));
        $this->assertSame('Theme Test Site', agora_site_name($this->db));
    }

    /**
     * @param non-empty-string $dir
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}
