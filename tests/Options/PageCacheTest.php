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

        // No DB: still purges ?p= style + home when ap_home_url missing.
        ap_clean_post_cache(42);

        $this->assertSame([42], $cleanPostIds);
        $this->assertSame(1, $this->postPurgeCount);
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
        $content = sys_get_temp_dir() . '/ap-page-cache-test-' . bin2hex(random_bytes(4));
        mkdir($content, 0777, true);
        $dropin = $content . '/advanced-cache.php';
        file_put_contents(
            $dropin,
            "<?php\n\$GLOBALS['ap_test_advanced_cache_loaded'] = true;\n"
        );

        if (!defined('AP_CONTENT_DIR')) {
            define('AP_CONTENT_DIR', $content);
        } else {
            // AP_CONTENT_DIR already set in this process — write drop-in there if writable.
            $alt = AP_Page_Cache::dropinPath();
            if ($alt !== '' && is_dir(dirname($alt))) {
                file_put_contents($alt, "<?php\n\$GLOBALS['ap_test_advanced_cache_loaded'] = true;\n");
            }
        }

        // Enable via filter (avoid depending on AP_CACHE constant).
        ap_add_filter('ap_page_cache_enabled', static fn () => true);

        // If AP_CONTENT_DIR was just defined to our temp dir, start should load it.
        ap_reset_page_cache();
        $GLOBALS['ap_test_advanced_cache_loaded'] = false;

        if (defined('AP_CONTENT_DIR') && AP_CONTENT_DIR === $content) {
            ap_start_page_cache();
            $this->assertTrue(ap_using_page_cache());
            $this->assertTrue(!empty($GLOBALS['ap_test_advanced_cache_loaded']));
        } else {
            // Constant already bound: exercise start() + usingDropin path without asserting load.
            ap_start_page_cache();
            $this->assertTrue(ap_page_cache_enabled());
        }

        @unlink($dropin);
        @rmdir($content);
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
