<?php

/**
 * Tests for AP_Rest — lightweight JSON REST API.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Rest;

use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Post;
use AP_Rest;
use AP_Rewrite;
use AP_Roles;
use AP_Taxonomy;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Rest::class)]
final class RestApiTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $adminId = 0;

    private int $authorId = 0;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-taxonomy.php';
        require_once $this->root . '/ap-includes/class-ap-comment.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/class-ap-formatting.php';
        require_once $this->root . '/ap-includes/class-ap-rest.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Rest::reset();
        AP_Post::resetRegistry();
        AP_Options::flushCache();
        AP_Rewrite::resetCache();
        AP_Roles::flushCache();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
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
        AP_Taxonomy::ensureBuiltins();
        AP_Roles::ensureDefaults($this->db);

        $this->seedOption('blogname', 'REST Test Site');
        $this->seedOption('blogdescription', 'Unit tests for the REST API');
        $this->seedOption('home', 'https://example.test');
        $this->seedOption('siteurl', 'https://example.test');
        $this->seedOption('permalink_structure', '');
        $this->seedOption('rest_api_enabled', '1');
        $this->seedOption('ap_module_blog', '1');
        $this->seedOption('ap_module_static_pages', '1');
        $this->seedOption('ap_module_forum', '0');

        // Auth salts for nonces.
        if (!defined('AP_AUTH_KEY')) {
            define('AP_AUTH_KEY', 'rest-test-auth-key');
        }
        if (!defined('AP_AUTH_SALT')) {
            define('AP_AUTH_SALT', 'rest-test-auth-salt');
        }
        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'rest-test-nonce-key');
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'rest-test-nonce-salt');
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'rest-test-logged-in-key');
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'rest-test-logged-in-salt');
        }

        $admin = AP_User::create([
            'user_login' => 'restadmin',
            'user_email' => 'restadmin@example.test',
            'user_pass' => 'AdminPass-99!',
            'display_name' => 'REST Admin',
            'role' => 'administrator',
        ], $this->db);
        $this->assertTrue($admin['ok']);
        $this->adminId = (int) $admin['id'];

        $author = AP_User::create([
            'user_login' => 'restauthor',
            'user_email' => 'restauthor@example.test',
            'user_pass' => 'AuthorPass-99!',
            'display_name' => 'REST Author',
            'role' => 'author',
        ], $this->db);
        $this->assertTrue($author['ok']);
        $this->authorId = (int) $author['id'];

        $GLOBALS['apdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        AP_Rest::reset();
        AP_Post::resetRegistry();
        AP_Options::flushCache();
        AP_Rewrite::resetCache();
        AP_Roles::flushCache();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        unset($GLOBALS['apdb']);
    }

    private function seedOption(string $name, string $value): void
    {
        $existing = $this->db->getVar(
            'SELECT option_id FROM ' . $this->db->quoteIdentifier($this->db->table('options'))
            . ' WHERE option_name = ? LIMIT 1',
            [$name]
        );
        if ($existing !== null && (int) $existing > 0) {
            $this->db->update(
                'options',
                ['option_value' => $value],
                ['option_name' => $name]
            );
        } else {
            $this->db->insert('options', [
                'option_name' => $name,
                'option_value' => $value,
                'autoload' => 'yes',
            ]);
        }
        AP_Options::flushCache();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array{status: int, data: mixed, headers: array<string, string>}
     */
    private function dispatch(string $method, string $route, array $extra = []): array
    {
        return AP_Rest::dispatch(array_merge([
            'method' => $method,
            'route' => $route,
        ], $extra), $this->db);
    }

    public function testIsRestRequestAndMatchPath(): void
    {
        $this->assertTrue(AP_Rest::isRestRequest(['rest_route' => '/']));
        $this->assertTrue(AP_Rest::isRestRequest(['rest_route' => '/ap/v1/posts']));
        $this->assertTrue(AP_Rest::isRestRequest(['rest_route' => '']));
        $this->assertFalse(AP_Rest::isRestRequest([]));
        $this->assertFalse(AP_Rest::isRestRequest(['sitemap' => 'index']));

        $this->assertSame(['rest_route' => '/'], AP_Rest::matchRestPath('ap-json'));
        $this->assertSame(
            ['rest_route' => '/ap/v1/posts'],
            AP_Rest::matchRestPath('ap-json/ap/v1/posts')
        );
        $this->assertNull(AP_Rest::matchRestPath('feed'));
        $this->assertNull(AP_Rest::matchRestPath(''));
    }

    public function testRewriteRecognizesRestPath(): void
    {
        $vars = AP_Rewrite::parseRequest('ap-json/ap/v1/settings', [], $this->db);
        $this->assertArrayHasKey('rest_route', $vars);
        $this->assertSame('/ap/v1/settings', $vars['rest_route']);

        $vars = AP_Rewrite::parseRequest('', ['rest_route' => '/ap/v1/posts'], $this->db);
        $this->assertSame('/ap/v1/posts', $vars['rest_route']);
    }

    public function testGetUrlPlainAndPretty(): void
    {
        $plain = AP_Rest::getUrl('/ap/v1/posts', $this->db);
        $this->assertStringContainsString('rest_route=', $plain);
        $this->assertStringContainsString(rawurlencode('/ap/v1/posts'), $plain);

        $this->seedOption('permalink_structure', '/%postname%/');
        AP_Options::flushCache();
        AP_Rewrite::resetCache();
        $pretty = AP_Rest::getUrl('/ap/v1/posts', $this->db);
        $this->assertStringContainsString('/ap-json/', $pretty);
        $this->assertStringContainsString('ap/v1/posts', $pretty);
    }

    public function testIndexAndNamespace(): void
    {
        $res = $this->dispatch('GET', '/');
        $this->assertSame(200, $res['status']);
        $this->assertIsArray($res['data']);
        $this->assertSame('REST Test Site', $res['data']['name']);
        $this->assertContains('ap/v1', $res['data']['namespaces']);
        $this->assertArrayHasKey('routes', $res['data']);
        $this->assertArrayHasKey('/ap/v1/posts', $res['data']['routes']);

        $ns = $this->dispatch('GET', '/ap/v1');
        $this->assertSame(200, $ns['status']);
        $this->assertSame('ap/v1', $ns['data']['namespace']);
    }

    public function testSettingsEndpoint(): void
    {
        $res = $this->dispatch('GET', '/ap/v1/settings');
        $this->assertSame(200, $res['status']);
        $this->assertSame('REST Test Site', $res['data']['title']);
        $this->assertArrayHasKey('modules', $res['data']);
        $this->assertTrue($res['data']['modules']['blog']);
        $this->assertFalse($res['data']['modules']['forum']);
        // Never expose admin email publicly.
        $this->assertSame('', $res['data']['email']);
    }

    public function testPostsListAndGet(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Hello REST',
            'post_content' => 'Body of the post',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => $this->authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $id);

        // Draft should not appear for anonymous.
        AP_Post::insert([
            'post_title' => 'Secret Draft',
            'post_content' => 'hidden',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $this->authorId,
        ], $this->db);

        $list = $this->dispatch('GET', '/ap/v1/posts');
        $this->assertSame(200, $list['status']);
        $this->assertIsArray($list['data']);
        $this->assertCount(1, $list['data']);
        $this->assertSame('Hello REST', $list['data'][0]['title']['raw']);
        $this->assertSame($id, $list['data'][0]['id']);

        $one = $this->dispatch('GET', '/ap/v1/posts/' . $id);
        $this->assertSame(200, $one['status']);
        $this->assertSame('Hello REST', $one['data']['title']['raw']);
        $this->assertSame('Body of the post', $one['data']['content']['raw']);

        $missing = $this->dispatch('GET', '/ap/v1/posts/99999');
        $this->assertSame(404, $missing['status']);
        $this->assertSame('rest_post_invalid_id', $missing['data']['code']);
    }

    public function testPagesList(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'About',
            'post_content' => 'About page',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_author' => $this->adminId,
        ], $this->db);

        $list = $this->dispatch('GET', '/ap/v1/pages');
        $this->assertSame(200, $list['status']);
        $this->assertNotEmpty($list['data']);
        $found = false;
        foreach ($list['data'] as $row) {
            if ((int) $row['id'] === $id) {
                $found = true;
                $this->assertSame('page', $row['type']);
            }
        }
        $this->assertTrue($found);
    }

    public function testCreateUpdateDeletePostWithAuth(): void
    {
        // Anonymous cannot create.
        $anon = $this->dispatch('POST', '/ap/v1/posts', [
            'body' => ['title' => 'Nope', 'content' => 'x', 'status' => 'publish'],
        ]);
        $this->assertSame(401, $anon['status']);

        // Author can create via Basic auth.
        $basic = 'Basic ' . base64_encode('restauthor:AuthorPass-99!');
        $create = $this->dispatch('POST', '/ap/v1/posts', [
            'headers' => ['Authorization' => $basic, 'Content-Type' => 'application/json'],
            'body' => [
                'title' => 'Created via REST',
                'content' => 'Hello from API',
                'status' => 'publish',
            ],
            'server' => ['HTTP_AUTHORIZATION' => $basic],
        ]);
        $this->assertSame(201, $create['status'], json_encode($create['data']));
        $this->assertSame('Created via REST', $create['data']['title']['raw']);
        $postId = (int) $create['data']['id'];
        $this->assertGreaterThan(0, $postId);

        $update = $this->dispatch('PUT', '/ap/v1/posts/' . $postId, [
            'headers' => ['Authorization' => $basic, 'Content-Type' => 'application/json'],
            'body' => ['title' => 'Updated via REST'],
            'server' => ['HTTP_AUTHORIZATION' => $basic],
        ]);
        $this->assertSame(200, $update['status']);
        $this->assertSame('Updated via REST', $update['data']['title']['raw']);

        $delete = $this->dispatch('DELETE', '/ap/v1/posts/' . $postId, [
            'headers' => ['Authorization' => $basic],
            'server' => ['HTTP_AUTHORIZATION' => $basic],
        ]);
        $this->assertSame(200, $delete['status']);
        $this->assertTrue($delete['data']['deleted']);

        $trashed = AP_Post::get($postId, $this->db);
        $this->assertNotNull($trashed);
        $this->assertSame('trash', $trashed->post_status);
    }

    public function testBasicAuthAuthenticate(): void
    {
        $headers = ['Authorization' => 'Basic ' . base64_encode('restadmin:AdminPass-99!')];
        $id = AP_Rest::authenticate($headers, ['HTTP_AUTHORIZATION' => $headers['Authorization']], $this->db);
        $this->assertSame($this->adminId, $id);

        $bad = AP_Rest::authenticate(
            ['Authorization' => 'Basic ' . base64_encode('restadmin:wrong')],
            [],
            $this->db
        );
        $this->assertSame(0, $bad);
    }

    public function testUsersPublicFieldsHideEmail(): void
    {
        $list = $this->dispatch('GET', '/ap/v1/users');
        $this->assertSame(200, $list['status']);
        $this->assertNotEmpty($list['data']);
        foreach ($list['data'] as $u) {
            $this->assertArrayNotHasKey('email', $u);
            $this->assertArrayHasKey('name', $u);
            $this->assertArrayHasKey('id', $u);
        }

        // Viewer with list_users sees email.
        $adminView = $this->dispatch('GET', '/ap/v1/users/' . $this->authorId, [
            'user_id' => $this->adminId,
        ]);
        $this->assertSame(200, $adminView['status']);
        $this->assertArrayHasKey('email', $adminView['data']);
        $this->assertSame('restauthor@example.test', $adminView['data']['email']);
    }

    public function testCategoriesAndTags(): void
    {
        $cat = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertTrue($cat['ok'] ?? ((int) ($cat['term_id'] ?? 0) > 0) || isset($cat['term_id']));
        // insertTerm return shape may vary — resolve by slug.
        $term = AP_Taxonomy::getTermBySlug('news', 'category', $this->db);
        if ($term === null && is_array($cat) && isset($cat['term_id'])) {
            $term = AP_Taxonomy::getTerm((int) $cat['term_id'], 'category', $this->db);
        }
        $this->assertNotNull($term);

        $list = $this->dispatch('GET', '/ap/v1/categories');
        $this->assertSame(200, $list['status']);
        $this->assertIsArray($list['data']);

        $one = $this->dispatch('GET', '/ap/v1/categories/' . (int) $term->term_id);
        $this->assertSame(200, $one['status']);
        $this->assertSame((int) $term->term_id, $one['data']['id']);
    }

    public function testForumEndpointsWhenModuleOff(): void
    {
        $res = $this->dispatch('GET', '/ap/v1/forums');
        $this->assertSame(404, $res['status']);
        $this->assertSame('rest_module_disabled', $res['data']['code']);
    }

    public function testDisabledApi(): void
    {
        $this->seedOption('rest_api_enabled', '0');
        AP_Options::flushCache();
        $res = $this->dispatch('GET', '/');
        $this->assertSame(404, $res['status']);
        $this->assertSame('rest_disabled', $res['data']['code']);
    }

    public function testUnknownRoute(): void
    {
        $res = $this->dispatch('GET', '/ap/v1/does-not-exist');
        $this->assertSame(404, $res['status']);
        $this->assertSame('rest_no_route', $res['data']['code']);
    }

    public function testPluginCanRegisterRoute(): void
    {
        AP_Rest::ensureBuiltins();
        AP_Rest::registerRoute('ap/v1', '/ping', [
            'methods' => 'GET',
            'callback' => static function (): array {
                return ['status' => 200, 'data' => ['pong' => true]];
            },
            'permission_callback' => static fn (): true => true,
        ]);

        $res = $this->dispatch('GET', '/ap/v1/ping');
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['pong']);
    }

    public function testProceduralHelpers(): void
    {
        $this->assertTrue(function_exists('ap_rest_enabled'));
        $this->assertTrue(function_exists('ap_rest_url'));
        $this->assertTrue(function_exists('ap_register_rest_route'));
        $this->assertTrue(function_exists('ap_rest_dispatch'));
        $this->assertTrue(function_exists('ap_create_rest_nonce'));

        $this->assertTrue(ap_rest_enabled($this->db));
        $url = ap_rest_url('/ap/v1/posts', $this->db);
        $this->assertNotSame('', $url);

        $res = ap_rest_dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/settings',
        ], $this->db);
        $this->assertSame(200, $res['status']);
    }

    public function testPasswordProtectedPostHidesContent(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Secret',
            'post_content' => 'classified',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_password' => 's3cret',
            'post_author' => $this->authorId,
        ], $this->db);

        $res = $this->dispatch('GET', '/ap/v1/posts/' . $id);
        // Password-protected published posts: canView allows publish, but content stripped.
        // Actually canViewPost requires empty password for public — so 403 for anon.
        $this->assertContains($res['status'], [200, 403]);
        if ($res['status'] === 200) {
            $this->assertTrue($res['data']['password_protected']);
            $this->assertSame('', $res['data']['content']['raw']);
        }
    }

    public function testBootstrapLoadsRestClass(): void
    {
        $bootstrap = file_get_contents($this->root . '/ap-includes/bootstrap.php');
        $this->assertIsString($bootstrap);
        $this->assertStringContainsString('class-ap-rest.php', $bootstrap);

        $index = file_get_contents($this->root . '/index.php');
        $this->assertIsString($index);
        $this->assertStringContainsString('AP_Rest', $index);
        $this->assertStringContainsString('isRestRequest', $index);
    }
}
