<?php

/**
 * Tests for ap_comments_template(): locate, fallback form, applicability.
 *
 * SPEC minimum: no theme file still renders a form; non-singular prints
 * nothing; Agora single.php still has exactly one Leave-a-comment form.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Comment;

use AP_Comment;
use AP_DB;
use AP_Editor;
use AP_Migrator;
use AP_Options;
use AP_Post;
use AP_Query;
use AP_Theme;
use PDO;
use PHPUnit\Framework\TestCase;

final class CommentsTemplateTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-comment.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/class-ap-formatting.php';
        require_once $this->root . '/ap-includes/class-ap-content-format.php';
        require_once $this->root . '/ap-includes/class-ap-editor.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/class-ap-theme.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-includes/template-tags.php';

        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('c', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('d', 32));
        }

        AP_Post::resetRegistry();
        AP_Comment::resetSpamCheckers();
        AP_Editor::reset();
        AP_Theme::reset();
        AP_Options::flushCache();
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
            'option_name' => 'home',
            'option_value' => 'https://example.test',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'siteurl',
            'option_value' => 'https://example.test',
            'autoload' => 'yes',
        ]);

        $this->tempThemes = sys_get_temp_dir() . '/ap-comments-tpl-' . uniqid('', true);
        $this->assertTrue(mkdir($this->tempThemes, 0700, true));
        $GLOBALS['apdb'] = $this->db;
        $this->useBareTheme();
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
        AP_Comment::resetSpamCheckers();
        AP_Editor::reset();
        AP_Theme::reset();
        AP_Options::flushCache();
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post'], $GLOBALS['apdb']);
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->removeDir($this->tempThemes);
    }

    public function testCompatFileIsReadable(): void
    {
        $path = ap_comments_compat_file();
        $this->assertNotSame('', $path);
        $this->assertFileIsReadable($path);
        $this->assertStringEndsWith('/ap-includes/theme-compat/comments.php', str_replace('\\', '/', $path));
    }

    public function testNoThemeCommentsFileUsesCoreFallback(): void
    {
        $this->assertFileDoesNotExist($this->tempThemes . '/bare-theme/comments.php');

        $located = ap_locate_comments_template(null, $this->db);
        $this->assertSame(
            realpath(ap_comments_compat_file()),
            realpath($located)
        );
    }

    public function testAgoraThemeCommentsPhpWinsOverFallback(): void
    {
        $this->useAgoraTheme();

        $expected = $this->root . '/ap-content/themes/agora/comments.php';
        $this->assertFileIsReadable($expected);
        $located = ap_locate_comments_template(null, $this->db);
        $this->assertSame(realpath($expected), realpath($located));
        $this->assertNotSame(realpath(ap_comments_compat_file()), realpath($located));
    }

    public function testThemeCommentsPhpWinsOverFallback(): void
    {
        $theme = $this->tempThemes . '/solo-theme';
        $this->assertTrue(mkdir($theme, 0700, true));
        file_put_contents($theme . '/style.css', "/*\nTheme Name: Solo\n*/\n");
        file_put_contents($theme . '/comments.php', "<?php echo 'THEME_COMMENTS';\n");

        AP_Theme::setThemesRootOverride($this->tempThemes);
        AP_Theme::setActiveOverride('solo-theme', 'solo-theme');

        $located = ap_locate_comments_template(null, $this->db);
        $this->assertSame($theme . '/comments.php', $located);
        $this->assertNotSame(realpath(ap_comments_compat_file()), realpath($located));
    }

    public function testChildCommentsPhpWinsOverParent(): void
    {
        $parent = $this->tempThemes . '/parent-theme';
        $child = $this->tempThemes . '/child-theme';
        $this->assertTrue(mkdir($parent, 0700, true));
        $this->assertTrue(mkdir($child, 0700, true));
        file_put_contents($parent . '/style.css', "/*\nTheme Name: Parent Theme\n*/\n");
        file_put_contents($child . '/style.css', "/*\nTheme Name: Child Theme\nTemplate: parent-theme\n*/\n");
        file_put_contents($parent . '/comments.php', "<?php echo 'PARENT_COMMENTS';\n");
        file_put_contents($child . '/comments.php', "<?php echo 'CHILD_COMMENTS';\n");

        AP_Theme::setThemesRootOverride($this->tempThemes);
        AP_Theme::setActiveOverride('child-theme', 'parent-theme');

        $this->assertSame(
            $child . '/comments.php',
            ap_locate_comments_template(null, $this->db)
        );
    }

    public function testParentCommentsPhpUsedWhenChildHasNone(): void
    {
        $parent = $this->tempThemes . '/parent-theme';
        $child = $this->tempThemes . '/child-theme';
        $this->assertTrue(mkdir($parent, 0700, true));
        $this->assertTrue(mkdir($child, 0700, true));
        file_put_contents($parent . '/style.css', "/*\nTheme Name: Parent Theme\n*/\n");
        file_put_contents($child . '/style.css', "/*\nTheme Name: Child Theme\nTemplate: parent-theme\n*/\n");
        file_put_contents($parent . '/comments.php', "<?php echo 'PARENT_COMMENTS';\n");

        AP_Theme::setThemesRootOverride($this->tempThemes);
        AP_Theme::setActiveOverride('child-theme', 'parent-theme');

        $this->assertSame(
            $parent . '/comments.php',
            ap_locate_comments_template(null, $this->db)
        );
    }

    public function testExplicitReadableFileWins(): void
    {
        $this->useAgoraTheme();

        $custom = $this->tempThemes . '/custom-comments.php';
        file_put_contents($custom, "<?php echo 'CUSTOM_COMMENTS';\n");

        $this->assertSame(
            $custom,
            ap_locate_comments_template($custom, $this->db)
        );
    }

    public function testExplicitThemeRelativeFileIsLocated(): void
    {
        $theme = $this->tempThemes . '/solo-theme';
        $this->assertTrue(mkdir($theme, 0700, true));
        file_put_contents($theme . '/style.css', "/*\nTheme Name: Solo\n*/\n");
        file_put_contents($theme . '/comments.php', "<?php echo 'DEFAULT_COMMENTS';\n");
        file_put_contents($theme . '/discussion.php', "<?php echo 'DISCUSSION';\n");

        AP_Theme::setThemesRootOverride($this->tempThemes);
        AP_Theme::setActiveOverride('solo-theme', 'solo-theme');

        $this->assertSame(
            $theme . '/discussion.php',
            ap_locate_comments_template('discussion.php', $this->db)
        );
        $this->assertSame(
            $theme . '/discussion.php',
            ap_locate_comments_template('/discussion.php', $this->db)
        );
    }

    public function testUnreadableExplicitFileFallsThroughToThemeThenFallback(): void
    {
        $located = ap_locate_comments_template('missing-comments.php', $this->db);
        $this->assertSame(
            realpath(ap_comments_compat_file()),
            realpath($located)
        );
    }

    public function testUnreadableExplicitFileUsesAgoraCommentsPhpWhenPresent(): void
    {
        $this->useAgoraTheme();

        $located = ap_locate_comments_template('missing-comments.php', $this->db);
        $this->assertSame(
            realpath($this->root . '/ap-content/themes/agora/comments.php'),
            realpath($located)
        );
    }

    public function testPathTraversalIsRejected(): void
    {
        $probe = '../../../ap-includes/version.php';
        $located = ap_locate_comments_template($probe, $this->db);
        $this->assertSame(
            realpath(ap_comments_compat_file()),
            realpath($located)
        );
        $this->assertStringNotContainsString('version.php', $located);
    }

    public function testCommentsTemplateLoadsLocatedFallback(): void
    {
        $this->seedLoopPost();

        ob_start();
        ap_comments_template();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('ap-comments--compat', $html);
        $this->assertStringContainsString('id="comments"', $html);
        $this->assertStringContainsString('Leave a comment', $html);
        $this->assertStringContainsString('value="ap_comment_post"', $html);
        $this->assertStringContainsString('id="respond"', $html);
        $this->assertSame(1, substr_count($html, 'value="ap_comment_post"'));
        $this->assertSame(1, substr_count($html, 'id="respond"'));
    }

    public function testNoThemeFileStillRendersFallbackForm(): void
    {
        $theme = $this->tempThemes . '/bare-theme';
        $this->assertFileDoesNotExist($theme . '/comments.php');
        $this->writeThemeSingleCallingCommentsTemplate($theme);

        $this->assertSame(
            realpath(ap_comments_compat_file()),
            realpath(ap_locate_comments_template(null, $this->db))
        );

        $this->seedLoopPost(['post_content' => 'Bare single body']);

        ob_start();
        AP_Theme::render($GLOBALS['ap_query'], $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('SINGLE_START', $html);
        $this->assertStringContainsString('Bare single body', $html);
        $this->assertStringContainsString('SINGLE_END', $html);
        $this->assertStringContainsString('ap-comments--compat', $html);
        $this->assertStringContainsString('Leave a comment', $html);
        $this->assertStringContainsString('value="ap_comment_post"', $html);
        $this->assertStringContainsString('name="ap_comment_action"', $html);
        $this->assertStringContainsString('id="respond"', $html);
        $this->assertStringContainsString('ap-editor', $html);
        $this->assertSame(1, substr_count($html, 'value="ap_comment_post"'));
        $this->assertSame(1, substr_count($html, 'id="respond"'));
        $this->assertSame(1, substr_count($html, 'Leave a comment'));
    }

    public function testCommentsTemplateLoadsThemeFile(): void
    {
        $theme = $this->tempThemes . '/solo-theme';
        $this->assertTrue(mkdir($theme, 0700, true));
        file_put_contents($theme . '/style.css', "/*\nTheme Name: Solo\n*/\n");
        file_put_contents($theme . '/comments.php', "<?php echo 'THEME_COMMENTS_LOADED';\n");

        AP_Theme::setThemesRootOverride($this->tempThemes);
        AP_Theme::setActiveOverride('solo-theme', 'solo-theme');

        $this->seedLoopPost();

        ob_start();
        ap_comments_template();
        $html = (string) ob_get_clean();

        $this->assertSame('THEME_COMMENTS_LOADED', $html);
        $this->assertStringNotContainsString('ap-comments--compat', $html);
    }

    public function testCommentsTemplatePrintsNothingWithoutMainQuery(): void
    {
        $this->seedLoopPost();
        unset($GLOBALS['ap_query']);

        $this->assertSame('', $this->renderCommentsTemplate());
    }

    public function testCommentsTemplatePrintsNothingOnHomeEvenWithThemeFile(): void
    {
        $theme = $this->tempThemes . '/solo-theme';
        $this->assertTrue(mkdir($theme, 0700, true));
        file_put_contents($theme . '/style.css', "/*\nTheme Name: Solo\n*/\n");
        file_put_contents($theme . '/comments.php', "<?php echo 'THEME_COMMENTS_LOADED';\n");

        AP_Theme::setThemesRootOverride($this->tempThemes);
        AP_Theme::setActiveOverride('solo-theme', 'solo-theme');

        $post = $this->seedLoopPost();
        $home = $this->setMainQuery(['post_type' => 'post']);
        $this->assertFalse($home->is_singular);
        $GLOBALS['ap_post'] = $post;

        $this->assertSame('', $this->renderCommentsTemplate());
    }

    public function testCommentsTemplatePrintsNothingOnSearch(): void
    {
        $post = $this->seedLoopPost();
        $search = $this->setMainQuery([
            's' => 'Discuss',
            'post_type' => 'post',
        ]);
        $this->assertTrue($search->is_search);
        $this->assertFalse($search->is_singular);
        $GLOBALS['ap_post'] = $post;

        $this->assertSame('', $this->renderCommentsTemplate());
    }

    public function testCommentsTemplatePrintsNothingOnFeed(): void
    {
        $post = $this->seedLoopPost();
        $feed = $this->setMainQuery([
            'p' => $post->ID,
            'feed' => 'rss2',
        ]);
        $this->assertTrue($feed->is_singular);
        $this->assertTrue($feed->is_feed);

        $this->assertSame('', $this->renderCommentsTemplate());
    }

    public function testCommentsTemplatePrintsNothingOnSingular404(): void
    {
        $missing = $this->setMainQuery(['p' => 999999]);
        $this->assertTrue($missing->is_singular);
        $this->assertTrue($missing->is_404);

        $this->assertSame('', $this->renderCommentsTemplate());
    }

    public function testCommentsTemplatePrintsNothingWhenBlogModuleOff(): void
    {
        $this->seedLoopPost();
        AP_Options::update('ap_module_blog', '0', $this->db);

        $this->assertFalse(ap_is_module_enabled('blog', $this->db));
        $this->assertSame('', $this->renderCommentsTemplate());
    }

    public function testCommentsTemplatePrintsNothingWhenCommentsClosedAndEmpty(): void
    {
        $this->seedLoopPost(['comment_status' => 'closed']);

        $this->assertSame('', $this->renderCommentsTemplate());
    }

    public function testCommentsTemplatePrintsNothingOnPageWithoutCommentsSupport(): void
    {
        $this->seedLoopPost([
            'post_type' => 'page',
            'post_title' => 'About',
            'comment_status' => 'open',
        ]);

        $this->assertFalse(ap_post_type_supports('page', 'comments'));
        $this->assertSame('', $this->renderCommentsTemplate());
    }

    public function testNonSingularPrintsEmptyCommentsMarkup(): void
    {
        $theme = $this->tempThemes . '/solo-theme';
        $this->assertTrue(mkdir($theme, 0700, true));
        file_put_contents($theme . '/style.css', "/*\nTheme Name: Solo\n*/\n");
        file_put_contents($theme . '/index.php', "<?php echo 'SOLO_INDEX';\n");
        file_put_contents($theme . '/comments.php', "<?php echo 'THEME_COMMENTS_LOADED';\n");

        AP_Theme::setThemesRootOverride($this->tempThemes);
        AP_Theme::setActiveOverride('solo-theme', 'solo-theme');

        $post = $this->seedLoopPost(['post_content' => 'Non-singular body']);

        $home = $this->setMainQuery(['post_type' => 'post']);
        $this->assertTrue($home->is_home);
        $this->assertFalse($home->is_singular);
        $GLOBALS['ap_post'] = $post;
        $this->assertSame('', $this->renderCommentsTemplate());

        $search = $this->setMainQuery([
            's' => 'Discuss',
            'post_type' => 'post',
        ]);
        $this->assertTrue($search->is_search);
        $this->assertFalse($search->is_singular);
        $GLOBALS['ap_post'] = $post;
        $this->assertSame('', $this->renderCommentsTemplate());

        $this->loadAgoraRenderDeps();
        $this->useAgoraTheme();
        $agoraHome = $this->setMainQuery(['post_type' => 'post']);
        $this->assertFalse($agoraHome->is_singular);

        ob_start();
        AP_Theme::render($GLOBALS['ap_query'], $this->db);
        $rendered = (string) ob_get_clean();

        $this->assertStringContainsString('Discuss', $rendered);
        $this->assertStringNotContainsString('THEME_COMMENTS_LOADED', $rendered);
        $this->assertStringNotContainsString('id="respond"', $rendered);
        $this->assertStringNotContainsString('value="ap_comment_post"', $rendered);
        $this->assertStringNotContainsString('Leave a comment', $rendered);
        $this->assertStringNotContainsString('ap-comments--compat', $rendered);
        $this->assertStringNotContainsString('ap-comment-form', $rendered);
    }

    public function testFallbackListsApprovedCommentsOnly(): void
    {
        $post = $this->seedLoopPost();
        AP_Comment::insert([
            'comment_post_ID' => $post->ID,
            'comment_author' => 'Visible Alice',
            'comment_content' => 'Approved body',
            'comment_approved' => '1',
        ], $this->db);
        AP_Comment::insert([
            'comment_post_ID' => $post->ID,
            'comment_author' => 'Hidden Bob',
            'comment_content' => 'Pending leak',
            'comment_approved' => '0',
        ], $this->db);
        AP_Comment::insert([
            'comment_post_ID' => $post->ID,
            'comment_author' => 'Spam Carol',
            'comment_content' => 'Spam leak',
            'comment_approved' => 'spam',
        ], $this->db);

        $html = $this->renderCommentsTemplate();

        $this->assertStringContainsString('ap-comments--compat', $html);
        $this->assertStringContainsString('1 comment', $html);
        $this->assertStringContainsString('Visible Alice', $html);
        $this->assertStringContainsString('Approved body', $html);
        $this->assertStringNotContainsString('Hidden Bob', $html);
        $this->assertStringNotContainsString('Pending leak', $html);
        $this->assertStringNotContainsString('Spam Carol', $html);
        $this->assertStringNotContainsString('Spam leak', $html);
    }

    public function testFallbackShowsClosedCopyAndNoForm(): void
    {
        $post = $this->seedLoopPost(['comment_status' => 'closed']);
        AP_Comment::insert([
            'comment_post_ID' => $post->ID,
            'comment_author' => 'Old Commenter',
            'comment_content' => 'Before close',
            'comment_approved' => '1',
        ], $this->db, ['check_open' => false]);

        $html = $this->renderCommentsTemplate();

        $this->assertStringContainsString('Old Commenter', $html);
        $this->assertStringContainsString('Before close', $html);
        $this->assertStringContainsString('Comments are closed.', $html);
        $this->assertStringNotContainsString('Leave a comment', $html);
        $this->assertStringNotContainsString('ap_comment_action', $html);
        $this->assertStringNotContainsString('ap-editor', $html);
        $this->assertStringNotContainsString('Log in', $html);
    }

    public function testFallbackShowsLoginToCommentWhenRegistrationRequired(): void
    {
        AP_Options::update('comment_registration', '1', $this->db);
        $this->seedLoopPost();

        $html = $this->renderCommentsTemplate();

        $this->assertStringContainsString('Leave a comment', $html);
        $this->assertStringContainsString('Log in', $html);
        $this->assertStringContainsString('to leave a comment.', $html);
        $this->assertStringContainsString('login.php', $html);
        $this->assertStringNotContainsString('name="ap_comment_action"', $html);
        $this->assertStringNotContainsString('ap-editor', $html);
        $this->assertStringNotContainsString('Comments are closed.', $html);
    }

    public function testFallbackRendersFormWithEditorAndSharedPostFields(): void
    {
        $post = $this->seedLoopPost();

        $html = $this->renderCommentsTemplate();

        $this->assertStringContainsString('Leave a comment', $html);
        $this->assertStringContainsString('id="respond"', $html);
        $this->assertStringContainsString('name="ap_comment_action"', $html);
        $this->assertStringContainsString('value="ap_comment_post"', $html);
        $this->assertStringContainsString('name="comment_post_ID"', $html);
        $this->assertStringContainsString('value="' . (int) $post->ID . '"', $html);
        $this->assertStringContainsString('name="comment_parent"', $html);
        $this->assertStringContainsString('name="comment"', $html);
        $this->assertStringContainsString('name="_ap_nonce"', $html);
        $this->assertStringContainsString('name="author"', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('ap-editor', $html);
        $this->assertStringContainsString('data-ap-editor-btn="spoiler"', $html);
        $this->assertStringContainsString('id="ap-comment-content"', $html);
        $this->assertStringNotContainsString('Comments are closed.', $html);
        $this->assertStringNotContainsString('Log in', $html);
    }

    public function testTheContentDoesNotAppendCommentsTemplate(): void
    {
        $this->seedLoopPost([
            'post_content' => 'Visible post body for content tag',
        ]);

        ob_start();
        ap_the_content();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Visible post body for content tag', $html);
        $this->assertStringNotContainsString('Leave a comment', $html);
        $this->assertStringNotContainsString('ap-comment-form', $html);
        $this->assertStringNotContainsString('ap_comment_action', $html);
        $this->assertStringNotContainsString('id="respond"', $html);
        $this->assertStringNotContainsString('id="comments"', $html);
    }

    public function testTheContentThenCommentsTemplateIsStillOneForm(): void
    {
        $this->seedLoopPost(['post_content' => 'Body then form']);

        ob_start();
        ap_the_content();
        ap_comments_template();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Body then form', $html);
        $this->assertSame(1, substr_count($html, 'value="ap_comment_post"'));
        $this->assertSame(1, substr_count($html, 'id="respond"'));
        $this->assertSame(1, substr_count($html, 'Leave a comment'));
        $this->assertStringContainsString('ap-comments--compat', $html);
    }

    public function testSingularThemeWithoutHelperCallPrintsNoCommentForm(): void
    {
        $theme = $this->tempThemes . '/silent-theme';
        $this->assertTrue(mkdir($theme, 0700, true));
        file_put_contents($theme . '/style.css', "/*\nTheme Name: Silent\n*/\n");
        file_put_contents($theme . '/index.php', "<?php echo 'SILENT_INDEX';\n");
        file_put_contents(
            $theme . '/single.php',
            "<?php\n"
            . "echo 'SINGLE_START';\n"
            . "if (function_exists('ap_the_content')) {\n"
            . "    ap_the_content();\n"
            . "}\n"
            . "echo 'SINGLE_END';\n"
        );

        AP_Theme::setThemesRootOverride($this->tempThemes);
        AP_Theme::setActiveOverride('silent-theme', 'silent-theme');

        $this->seedLoopPost(['post_content' => 'Silent theme body']);

        ob_start();
        AP_Theme::render($GLOBALS['ap_query'], $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('SINGLE_START', $html);
        $this->assertStringContainsString('Silent theme body', $html);
        $this->assertStringContainsString('SINGLE_END', $html);
        $this->assertStringNotContainsString('Leave a comment', $html);
        $this->assertStringNotContainsString('ap_comment_action', $html);
        $this->assertStringNotContainsString('ap-comment-form', $html);
        $this->assertStringNotContainsString('id="respond"', $html);
    }

    public function testAgoraSinglePhpCallsCommentsTemplateOnce(): void
    {
        $single = (string) file_get_contents(
            $this->root . '/ap-content/themes/agora/single.php'
        );
        $this->assertSame(1, substr_count($single, 'ap_comments_template('));
        $this->assertStringNotContainsString('ap_comment_action', $single);
        $this->assertStringNotContainsString('Leave a comment', $single);
        $this->assertStringNotContainsString('ap_editor(', $single);
    }

    public function testAgoraSingleRendersExactlyOneCommentForm(): void
    {
        $this->loadAgoraRenderDeps();
        $this->useAgoraTheme();
        $this->seedLoopPost(['post_content' => 'Agora single body']);

        ob_start();
        AP_Theme::render($GLOBALS['ap_query'], $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('ap-entry--single', $html);
        $this->assertStringContainsString('Agora single body', $html);
        $this->assertSame(1, substr_count($html, 'id="respond"'));
        $this->assertSame(1, substr_count($html, 'value="ap_comment_post"'));
        $this->assertSame(1, substr_count($html, 'Leave a comment'));
        $this->assertSame(1, substr_count($html, 'id="comments"'));
        $this->assertSame(1, substr_count($html, 'name="ap_comment_action"'));
        $this->assertStringContainsString('id="agora-comment-content"', $html);
        $this->assertStringNotContainsString('ap-comments--compat', $html);
    }

    public function testFallbackFormPostsThroughSharedHandler(): void
    {
        $post = $this->seedLoopPost();
        $html = $this->renderCommentsTemplate();

        $matched = preg_match(
            '/name="_ap_nonce"[^>]*value="([^"]+)"/',
            $html,
            $nonceMatch
        );
        $this->assertSame(1, $matched);
        $nonce = (string) ($nonceMatch[1] ?? '');
        $this->assertNotSame('', $nonce);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'ap_comment_action' => 'ap_comment_post',
            'comment_post_ID' => $post->ID,
            'comment_parent' => 0,
            '_ap_nonce' => $nonce,
            'author' => 'FormGuest',
            'email' => 'formguest@example.test',
            'comment' => 'Hello from the fallback form',
        ];

        $redirect = ap_handle_comment_form_post($this->db);
        $this->assertStringContainsString('comment_ok=pending', $redirect);

        $pending = AP_Comment::query([
            'post_id' => $post->ID,
            'status' => 'hold',
        ], $this->db);
        $this->assertNotEmpty($pending);
        $this->assertSame('FormGuest', $pending[0]->comment_author);
        $this->assertSame('Hello from the fallback form', $pending[0]->comment_content);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedLoopPost(array $overrides = []): AP_Post
    {
        $id = AP_Post::insert(array_merge([
            'post_title' => 'Discuss',
            'post_content' => 'Body',
            'post_status' => 'publish',
            'post_type' => 'post',
            'comment_status' => 'open',
            'post_author' => 1,
        ], $overrides), $this->db);
        $this->assertGreaterThan(0, $id);
        $post = AP_Post::get($id, $this->db);
        $this->assertInstanceOf(AP_Post::class, $post);

        $queryVars = ['p' => $id];
        if ((string) $post->post_type === 'page') {
            $queryVars = ['page_id' => $id];
        }
        $query = $this->setMainQuery($queryVars);
        $this->assertTrue($query->is_singular);
        $GLOBALS['ap_post'] = $post;

        return $post;
    }

    private function useAgoraTheme(): void
    {
        AP_Theme::setThemesRootOverride($this->root . '/ap-content/themes');
        AP_Theme::setActiveOverride('agora', 'agora');
    }

    private function useBareTheme(): void
    {
        $theme = $this->tempThemes . '/bare-theme';
        if (!is_dir($theme)) {
            $this->assertTrue(mkdir($theme, 0700, true));
            file_put_contents($theme . '/style.css', "/*\nTheme Name: Bare\n*/\n");
            file_put_contents($theme . '/index.php', "<?php echo 'BARE_INDEX';\n");
        }
        AP_Theme::setThemesRootOverride($this->tempThemes);
        AP_Theme::setActiveOverride('bare-theme', 'bare-theme');
    }

    /**
     * @param non-empty-string $themeDir
     */
    private function writeThemeSingleCallingCommentsTemplate(string $themeDir): void
    {
        file_put_contents(
            $themeDir . '/single.php',
            "<?php\n"
            . "echo 'SINGLE_START';\n"
            . "if (function_exists('ap_the_content')) {\n"
            . "    ap_the_content();\n"
            . "}\n"
            . "if (function_exists('ap_comments_template')) {\n"
            . "    ap_comments_template();\n"
            . "}\n"
            . "echo 'SINGLE_END';\n"
        );
    }

    private function loadAgoraRenderDeps(): void
    {
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-taxonomy.php';
        require_once $this->root . '/ap-includes/class-ap-nav-menu.php';
        require_once $this->root . '/ap-includes/class-ap-assets.php';
        require_once $this->root . '/ap-includes/class-ap-widgets.php';
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function setMainQuery(array $vars): AP_Query
    {
        $query = new AP_Query($vars, $this->db);
        $GLOBALS['ap_query'] = $query;
        if ($query->post instanceof AP_Post) {
            $GLOBALS['ap_post'] = $query->post;
        }

        return $query;
    }

    private function renderCommentsTemplate(): string
    {
        ob_start();
        ap_comments_template();

        return (string) ob_get_clean();
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
