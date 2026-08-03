<?php

/**
 * Tests for Page Cache hooks (AP_Page_Cache + ap_* helpers).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Options;

use AP_Page_Cache;
use AP_Post;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Page_Cache::class)]
final class PageCacheTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $purgedUrls = [];

    private int $flushCount = 0;

    private int $postPurgeCount = 0;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);

        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-page-cache.php';
        require_once $this->root . '/ap-includes/functions.php';

        ap_reset_hooks();
        ap_reset_page_cache();
        $this->purgedUrls = [];
        $this->flushCount = 0;
        $this->postPurgeCount = 0;

        ap_add_action('ap_page_cache_flush', function (): void {
            ++$this->flushCount;
        });
        ap_add_action('ap_page_cache_purge_url', function (string $url): void {
            $this->purgedUrls[] = $url;
        });
        ap_add_action('ap_page_cache_purge_post', function (): void {
            ++$this->postPurgeCount;
        });
    }

    protected function tearDown(): void
    {
        ap_reset_hooks();
        ap_reset_page_cache();
        if (defined('AP_CONTENT_DIR') && str_contains((string) AP_CONTENT_DIR, 'ap-page-cache-test-')) {
            // Constant cannot be undefined; leave for process lifetime.
        }
    }

    public function testCleanPageCacheFullFlushFiresAction(): void
    {
        ap_clean_page_cache();
        $this->assertSame(1, $this->flushCount);
        $this->assertSame([], $this->purgedUrls);
    }

    public function testCleanPageCacheUrlPurgesSingleUrl(): void
    {
        ap_clean_page_cache('https://example.com/hello/');
        $this->assertSame(0, $this->flushCount);
        $this->assertSame(['https://example.com/hello/'], $this->purgedUrls);
    }

    public function testCleanPostCacheFiresPurgeActions(): void
    {
        $cleanPostIds = [];
        ap_add_action('ap_clean_post_cache', function (int $id) use (&$cleanPostIds): void {
            $cleanPostIds[] = $id;
        });

        // No DB / partial bootstrap: must not throw, still signals purge hooks.
        // When AP_Post is loaded (classmap), get() may fail — urlsForPost catches it.
        // Plain ?p= token must still be emitted even if AP_Post is not loaded.
        ap_clean_post_cache(42);

        $this->assertSame([42], $cleanPostIds);
        $this->assertSame(1, $this->postPurgeCount);
        $this->assertNotEmpty(
            array_filter(
                $this->purgedUrls,
                static fn (string $u): bool => str_contains($u, 'p=42') || str_contains($u, '?p=42')
            ),
            'Expected a ?p=42 purge URL, got: ' . implode(', ', $this->purgedUrls)
        );
    }

    public function testUrlsForPostAlwaysIncludesPlainPermalinkToken(): void
    {
        // Independent of whether AP_Post autoload has run in this process.
        $urls = AP_Page_Cache::urlsForPost(99);
        $this->assertNotEmpty(
            array_filter(
                $urls,
                static fn (string $u): bool => str_contains($u, 'p=99')
            ),
            'Expected plain ?p=99 purge token, got: ' . implode(', ', $urls)
        );

        $this->assertSame([], AP_Page_Cache::urlsForPost(0));
    }

    public function testSkipAndShouldCacheRequest(): void
    {
        // CLI is non-cacheable by default.
        $this->assertFalse(ap_should_cache_request());

        // Even if we force the method filter, skip flag wins after filter order…
        // Override should_cache via filter to isolate skip behaviour.
        ap_add_filter('ap_should_cache_request', static fn () => true, 100);
        // Still CLI → shouldCacheRequest sets false before filter, then filter can force true.
        $this->assertTrue(ap_should_cache_request());

        ap_skip_page_cache();
        $this->assertTrue(ap_page_cache_skipped());

        // skip sets should=false first; our priority-100 filter forces true again.
        // Re-register a later filter that respects skip:
        ap_remove_all_filters('ap_should_cache_request');
        ap_add_filter('ap_should_cache_request', static function (bool $should): bool {
            return $should;
        }, 100);
        // skipRequest already true → base false → filter keeps false
        $this->assertFalse(ap_should_cache_request());
    }

    public function testPageCacheEnabledRespectsConstantAndFilter(): void
    {
        // Without AP_CACHE, disabled unless filter enables it.
        if (!defined('AP_CACHE')) {
            $this->assertFalse(ap_page_cache_enabled());
            ap_add_filter('ap_page_cache_enabled', static fn () => true);
            $this->assertTrue(ap_page_cache_enabled());
        } else {
            $this->assertSame((bool) AP_CACHE, ap_page_cache_enabled() || !AP_CACHE || ap_page_cache_enabled());
            // Filter can still disable.
            ap_add_filter('ap_page_cache_enabled', static fn () => false);
            $this->assertFalse(ap_page_cache_enabled());
        }
    }

    public function testInvalidationHookOnPostInserted(): void
    {
        AP_Page_Cache::registerInvalidationHooks();

        $purged = [];
        ap_add_action('ap_page_cache_purge_post', function (int $id) use (&$purged): void {
            $purged[] = $id;
        });

        ap_do_action('ap_post_inserted', 99, null);

        $this->assertContains(99, $purged);
    }

    public function testInvalidationHookOnCommentInserted(): void
    {
        AP_Page_Cache::registerInvalidationHooks();

        $purged = [];
        ap_add_action('ap_page_cache_purge_post', function (int $id) use (&$purged): void {
            $purged[] = $id;
        });

        $comment = (object) ['comment_post_ID' => 7, 'comment_ID' => 1];
        ap_do_action('ap_comment_inserted', 1, $comment);

        $this->assertContains(7, $purged);
    }

    public function testInvalidationHookOnTopicCreated(): void
    {
        AP_Page_Cache::registerInvalidationHooks();

        $topics = [];
        ap_add_action('ap_page_cache_purge_topic', function (int $id) use (&$topics): void {
            $topics[] = $id;
        });

        $topic = (object) ['forum_id' => 3, 'topic_id' => 5];
        ap_do_action('ap_topic_created', 5, $topic);

        $this->assertContains(5, $topics);
    }

    public function testGlobalLayoutChangeFlushes(): void
    {
        AP_Page_Cache::registerInvalidationHooks();

        ap_do_action('ap_activate_plugin', 'sample/sample.php');
        $this->assertGreaterThanOrEqual(1, $this->flushCount);

        $before = $this->flushCount;
        ap_do_action('ap_switch_theme', 'agora', 'agora');
        $this->assertGreaterThan($before, $this->flushCount);
    }

    public function testUrlsForPostFilter(): void
    {
        ap_add_filter('ap_page_cache_urls_for_post', static function (array $urls, int $postId): array {
            $urls[] = 'https://cdn.example/extra/' . $postId;

            return $urls;
        }, 10, 2);

        $urls = AP_Page_Cache::urlsForPost(12);
        $this->assertContains('https://cdn.example/extra/12', $urls);
    }

    public function testDropinPathUsesContentDir(): void
    {
        if (!defined('AP_ABSPATH')) {
            define('AP_ABSPATH', $this->root . '/');
        }
        $path = ap_page_cache_dropin_path();
        $this->assertStringEndsWith('/advanced-cache.php', $path);
        $this->assertStringContainsString('ap-content', $path);
    }

    public function testStartLoadsAdvancedCacheDropin(): void
    {
        // Never define AP_CONTENT_DIR to a temp path here: constants are process-wide
        // and would break later theme discovery (themesRoot uses AP_CONTENT_DIR).
        if (!defined('AP_ABSPATH')) {
            define('AP_ABSPATH', $this->root . '/');
        }

        $contentDir = defined('AP_CONTENT_DIR') && is_string(AP_CONTENT_DIR) && AP_CONTENT_DIR !== ''
            ? rtrim(str_replace('\\', '/', AP_CONTENT_DIR), '/')
            : rtrim(str_replace('\\', '/', $this->root), '/') . '/ap-content';

        $this->assertDirectoryExists($contentDir, 'ap-content must exist for drop-in path');

        $dropin = $contentDir . '/advanced-cache.php';
        $hadDropin = is_file($dropin);
        $previous = $hadDropin ? (string) file_get_contents($dropin) : null;

        file_put_contents(
            $dropin,
            "<?php\n\$GLOBALS['ap_test_advanced_cache_loaded'] = true;\n"
        );

        // Enable via filter (avoid depending on AP_CACHE constant).
        ap_add_filter('ap_page_cache_enabled', static fn () => true);

        ap_reset_page_cache();
        $GLOBALS['ap_test_advanced_cache_loaded'] = false;

        ap_start_page_cache();
        $this->assertTrue(ap_page_cache_enabled());
        $this->assertTrue(ap_using_page_cache());
        $this->assertTrue(!empty($GLOBALS['ap_test_advanced_cache_loaded']));

        if ($hadDropin && $previous !== null) {
            file_put_contents($dropin, $previous);
        } else {
            @unlink($dropin);
        }
    }

    public function testPostLifecycleActionsFireFromModel(): void
    {
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';

        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = \AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $GLOBALS['apdb'] = $db;

        $migrator = new \AP_Migrator($db, \AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        AP_Post::resetRegistry();
        AP_Post::ensureBuiltins();
        AP_Page_Cache::registerInvalidationHooks();

        $inserted = [];
        ap_add_action('ap_post_inserted', function (int $id) use (&$inserted): void {
            $inserted[] = $id;
        });

        $id = AP_Post::insert([
            'post_title' => 'Cache Hook Post',
            'post_content' => 'Hello',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $db);

        $this->assertGreaterThan(0, $id);
        $this->assertContains($id, $inserted);
        $this->assertGreaterThanOrEqual(1, $this->postPurgeCount);

        $updated = [];
        ap_add_action('ap_post_updated', function (int $postId) use (&$updated): void {
            $updated[] = $postId;
        });

        $this->assertTrue(AP_Post::update($id, ['post_title' => 'Updated'], $db));
        $this->assertContains($id, $updated);

        $this->assertTrue(AP_Post::trash($id, $db));
        $this->assertTrue(AP_Post::untrash($id, $db));
        $this->assertTrue(AP_Post::delete($id, true, $db));

        AP_Post::resetRegistry();
        unset($GLOBALS['apdb']);
    }
}
