<?php

/**
 * AgoraPress lightweight REST API.
 *
 * Public JSON API at /ap-json/ (pretty) or ?rest_route=/… (plain). Primary
 * namespace: ap/v1. Inspired by the WordPress REST API shape (not a fork).
 *
 * Built-in routes (module-aware):
 *   GET  /ap/v1               namespace index
 *   GET  /ap/v1/settings      public site settings
 *   GET  /ap/v1/posts[/{id}]  published posts (+ create/update/delete when auth)
 *   GET  /ap/v1/pages[/{id}]  published pages
 *   GET  /ap/v1/comments[/{id}]
 *   GET  /ap/v1/users[/{id}]  public user profiles
 *   GET  /ap/v1/categories[/{id}]  /ap/v1/tags[/{id}]
 *   GET  /ap/v1/forums[/{id}] /ap/v1/topics[/{id}]  (forum module)
 *
 * Plugins register routes on `ap_rest_api_init` via AP_Rest::registerRoute().
 *
 * Auth: session cookie (logged-in browser) and optional HTTP Basic
 * (username:password). Cookie-authenticated mutations require X-AP-Nonce
 * (or _ap_nonce body field) matching action "ap_rest".
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * REST route registry, request dispatch, and built-in resource controllers.
 */
class AP_Rest
{
    /** Option: master enable switch (default on). */
    public const OPTION_ENABLED = 'rest_api_enabled';

    /** Primary API namespace. */
    public const NAMESPACE = 'ap/v1';

    /** Pretty URL prefix (no leading/trailing slash). */
    public const URL_PREFIX = 'ap-json';

    /** Nonce action for cookie-authenticated write requests. */
    public const NONCE_ACTION = 'ap_rest';

    /**
     * Registered routes.
     *
     * @var array<string, array{
     *     methods: list<string>,
     *     route: string,
     *     pattern: string,
     *     callback: callable,
     *     permission_callback: callable|null,
     *     args: array<string, mixed>,
     *     schema: array<string, mixed>|null
     * }>
     */
    private static array $routes = [];

    /** Whether built-in routes have been registered this process. */
    private static bool $builtinsRegistered = false;

    /** Whether {@see registerBuiltins()} is mid-run (guards re-entry). */
    private static bool $registering = false;

    /**
     * Whether the REST API is enabled (option + filter).
     */
    public static function isEnabled(?AP_DB $db = null): bool
    {
        $enabled = true;
        if (class_exists('AP_Options', false)) {
            $enabled = (string) AP_Options::get(self::OPTION_ENABLED, '1', $db) !== '0';
        }
        if (function_exists('ap_apply_filters')) {
            $enabled = (bool) ap_apply_filters('ap_rest_enabled', $enabled, $db);
        }

        return $enabled;
    }

    /**
     * Whether rewrite/query vars request the REST API.
     *
     * @param array<string, mixed> $vars
     */
    public static function isRestRequest(array $vars): bool
    {
        if (!isset($vars['rest_route'])) {
            return false;
        }
        $r = $vars['rest_route'];
        if (is_bool($r)) {
            return $r;
        }
        if (is_string($r)) {
            return true; // empty string = index
        }

        return false;
    }

    /**
     * Public REST root URL (/ap-json/ or ?rest_route=/).
     */
    public static function getUrl(string $route = '', ?AP_DB $db = null): string
    {
        $route = self::normalizeRoute($route);
        if (class_exists('AP_Rewrite', false) && AP_Rewrite::usingPermalinks($db)) {
            $path = '/' . self::URL_PREFIX . '/';
            if ($route !== '/') {
                $path .= ltrim($route, '/') . '/';
            }

            return AP_Rewrite::homeUrl($path, $db);
        }

        $qs = '?rest_route=' . rawurlencode($route === '/' ? '/' : $route);

        if (class_exists('AP_Rewrite', false)) {
            return AP_Rewrite::homeUrl($qs, $db);
        }

        return $qs;
    }

    /**
     * Reset static state (unit tests).
     */
    public static function reset(): void
    {
        self::$routes = [];
        self::$builtinsRegistered = false;
        self::$registering = false;
    }

    /**
     * Register a route under a namespace.
     *
     * Route may include named captures: `/posts/(?P<id>\d+)`.
     * Methods: GET, POST, PUT, PATCH, DELETE, or array of those.
     *
     * Callback: function (array $request): array|AP_Rest_Response-like array
     *   $request keys: method, route, params, query, body, headers, user_id, db
     *
     * Permission callback: function (array $request): true|array error
     *   Return true to allow, or error array {code, message, status}.
     *
     * @param string               $namespace e.g. ap/v1
     * @param string               $route     e.g. /posts/(?P<id>\d+)
     * @param array<string, mixed> $args      methods, callback, permission_callback, args
     */
    public static function registerRoute(string $namespace, string $route, array $args): void
    {
        $namespace = trim($namespace, '/');
        $route = self::normalizeRoute($route);
        if ($namespace === '') {
            $full = $route === '/' ? '/' : $route;
        } else {
            $full = '/' . $namespace . ($route === '/' ? '' : $route);
        }

        $methods = $args['methods'] ?? 'GET';
        if (is_string($methods)) {
            $methods = array_map('strtoupper', array_map('trim', explode(',', $methods)));
        }
        if (!is_array($methods)) {
            $methods = ['GET'];
        }
        $methods = array_values(array_unique(array_filter(
            array_map(static fn ($m): string => strtoupper(trim((string) $m)), $methods),
            static fn (string $m): bool => $m !== ''
        )));
        if ($methods === []) {
            $methods = ['GET'];
        }

        $callback = $args['callback'] ?? null;
        if (!is_callable($callback)) {
            return;
        }

        $permission = $args['permission_callback'] ?? null;
        if ($permission !== null && !is_callable($permission)) {
            $permission = null;
        }

        $key = implode('|', $methods) . ' ' . $full;
        self::$routes[$key] = [
            'methods' => $methods,
            'route' => $full,
            'pattern' => self::routeToPattern($full),
            'callback' => $callback,
            'permission_callback' => $permission,
            'args' => is_array($args['args'] ?? null) ? $args['args'] : [],
            'schema' => is_array($args['schema'] ?? null) ? $args['schema'] : null,
        ];
    }

    /**
     * Registered route patterns (for index / debugging).
     *
     * @return list<array{methods: list<string>, route: string}>
     */
    public static function getRoutes(): array
    {
        self::ensureBuiltins();
        $out = [];
        foreach (self::$routes as $def) {
            $out[] = [
                'methods' => $def['methods'],
                'route' => $def['route'],
            ];
        }

        return $out;
    }

    /**
     * Ensure built-ins are registered once, then fire ap_rest_api_init.
     */
    public static function ensureBuiltins(): void
    {
        if (self::$builtinsRegistered || self::$registering) {
            return;
        }
        self::$registering = true;
        try {
            self::registerBuiltins();
            self::$builtinsRegistered = true;
            if (function_exists('ap_do_action')) {
                ap_do_action('ap_rest_api_init');
            }
        } finally {
            self::$registering = false;
        }
    }

    /**
     * Dispatch a REST request and return a response array (does not emit).
     *
     * @param array{
     *     method?: string,
     *     route?: string,
     *     query?: array<string, mixed>,
     *     body?: array<string, mixed>|string|null,
     *     headers?: array<string, string>,
     *     server?: array<string, mixed>,
     *     user_id?: int
     * } $input
     *
     * @return array{status: int, data: mixed, headers: array<string, string>}
     */
    public static function dispatch(array $input = [], ?AP_DB $db = null): array
    {
        if (!self::isEnabled($db)) {
            return self::errorResponse('rest_disabled', 'REST API is disabled.', 404);
        }

        self::ensureBuiltins();

        $method = strtoupper((string) ($input['method'] ?? 'GET'));
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        $route = self::normalizeRoute((string) ($input['route'] ?? '/'));
        $query = is_array($input['query'] ?? null) ? $input['query'] : [];
        $headers = self::normalizeHeaders(is_array($input['headers'] ?? null) ? $input['headers'] : []);
        $body = self::parseBody($input['body'] ?? null, $headers);
        $server = is_array($input['server'] ?? null) ? $input['server'] : [];

        $userId = isset($input['user_id']) ? (int) $input['user_id'] : 0;
        if ($userId < 1) {
            $userId = self::authenticate($headers, $server, $db);
        }

        $match = self::matchRoute($method, $route);
        if ($match === null) {
            // Allow OPTIONS for CORS preflight without a registered route.
            if ($method === 'OPTIONS') {
                return self::optionsResponse($route);
            }

            return self::errorResponse(
                'rest_no_route',
                'No route was found matching the URL and request method.',
                404
            );
        }

        $request = [
            'method' => $method,
            'route' => $route,
            'params' => $match['params'],
            'query' => $query,
            'body' => $body,
            'headers' => $headers,
            'user_id' => $userId,
            'db' => $db,
            'server' => $server,
        ];

        // Merge path params + query + body into params for convenience.
        $request['params'] = array_merge($query, $body, $match['params']);

        $def = $match['definition'];
        if (is_callable($def['permission_callback'])) {
            $perm = ($def['permission_callback'])($request);
            if ($perm !== true) {
                if (is_array($perm) && isset($perm['code'], $perm['message'])) {
                    return self::errorResponse(
                        (string) $perm['code'],
                        (string) $perm['message'],
                        (int) ($perm['status'] ?? 403)
                    );
                }

                return self::errorResponse(
                    'rest_forbidden',
                    'Sorry, you are not allowed to do that.',
                    403
                );
            }
        }

        try {
            $result = ($def['callback'])($request);
        } catch (Throwable $e) {
            return self::errorResponse(
                'rest_internal_error',
                'An unexpected error occurred.',
                500
            );
        }

        return self::normalizeResult($result);
    }

    /**
     * Emit JSON response headers + body and optionally exit.
     *
     * @param array<string, mixed> $vars Rewrite vars (must include rest_route).
     *
     * @return never|array{status: int, data: mixed, headers: array<string, string>}
     */
    public static function serve(array $vars = [], ?AP_DB $db = null, bool $exit = true): array
    {
        $route = isset($vars['rest_route']) ? (string) $vars['rest_route'] : '/';
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        $headers = [];
        if (function_exists('getallheaders')) {
            $raw = getallheaders();
            if (is_array($raw)) {
                foreach ($raw as $k => $v) {
                    if (is_string($k) && (is_string($v) || is_numeric($v))) {
                        $headers[$k] = (string) $v;
                    }
                }
            }
        }
        // Fallbacks for CGI / missing getallheaders.
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            if (!isset($headers[$name]) && (is_string($value) || is_numeric($value))) {
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }

        $rawBody = null;
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $rawBody = file_get_contents('php://input');
            if ($rawBody === false) {
                $rawBody = '';
            }
            // Prefer parsed $_POST when application/x-www-form-urlencoded.
            if ($rawBody === '' && $_POST !== []) {
                $rawBody = $_POST;
            }
        }

        $response = self::dispatch([
            'method' => $method,
            'route' => $route,
            'query' => $_GET,
            'body' => $rawBody,
            'headers' => $headers,
            'server' => $_SERVER,
        ], $db);

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_rest_served', $response, $route, $method);
        }

        if (class_exists('AP_Page_Cache', false)) {
            AP_Page_Cache::skipRequest();
            AP_Page_Cache::nocacheHeaders();
        }

        if (!headers_sent()) {
            http_response_code($response['status']);
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Robots-Tag: noindex');
            foreach ($response['headers'] as $name => $value) {
                if (is_string($name) && is_string($value) && $name !== '') {
                    header($name . ': ' . $value, true);
                }
            }
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        echo json_encode($response['data'], $flags);

        if ($exit) {
            exit(0);
        }

        return $response;
    }

    /**
     * Match path to rest_route for rewrite (always on, even without pretty permalinks).
     *
     * @return array<string, mixed>|null
     */
    public static function matchRestPath(string $path): ?array
    {
        $path = trim($path, '/');
        if ($path === '') {
            return null;
        }
        $prefix = self::URL_PREFIX;
        if (strcasecmp($path, $prefix) === 0) {
            return ['rest_route' => '/'];
        }
        if (preg_match('#^' . preg_quote($prefix, '#') . '/(.*)$#i', $path, $m) === 1) {
            $rest = '/' . trim(rawurldecode($m[1]), '/');
            if ($rest === '/') {
                return ['rest_route' => '/'];
            }

            return ['rest_route' => $rest];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Built-in routes
    // -------------------------------------------------------------------------

    private static function registerBuiltins(): void
    {
        // Root index.
        self::registerRoute('', '/', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleIndex'],
            'permission_callback' => static fn (): true => true,
        ]);
        // Also under empty namespace path that becomes just "/".
        // Primary namespace index.
        self::registerRoute(self::NAMESPACE, '/', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleNamespaceIndex'],
            'permission_callback' => static fn (): true => true,
        ]);

        self::registerRoute(self::NAMESPACE, '/settings', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleSettings'],
            'permission_callback' => static fn (): true => true,
        ]);

        // Posts.
        self::registerRoute(self::NAMESPACE, '/posts', [
            'methods' => 'GET',
            'callback' => [self::class, 'handlePostsList'],
            'permission_callback' => static fn (): true => true,
        ]);
        self::registerRoute(self::NAMESPACE, '/posts', [
            'methods' => 'POST',
            'callback' => [self::class, 'handlePostCreate'],
            'permission_callback' => [self::class, 'permCreatePost'],
        ]);
        self::registerRoute(self::NAMESPACE, '/posts/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'handlePostGet'],
            'permission_callback' => static fn (): true => true,
        ]);
        self::registerRoute(self::NAMESPACE, '/posts/(?P<id>\d+)', [
            'methods' => 'PUT,PATCH',
            'callback' => [self::class, 'handlePostUpdate'],
            'permission_callback' => [self::class, 'permEditPost'],
        ]);
        self::registerRoute(self::NAMESPACE, '/posts/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [self::class, 'handlePostDelete'],
            'permission_callback' => [self::class, 'permDeletePost'],
        ]);

        // Pages.
        self::registerRoute(self::NAMESPACE, '/pages', [
            'methods' => 'GET',
            'callback' => [self::class, 'handlePagesList'],
            'permission_callback' => static fn (): true => true,
        ]);
        self::registerRoute(self::NAMESPACE, '/pages/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'handlePageGet'],
            'permission_callback' => static fn (): true => true,
        ]);

        // Comments.
        self::registerRoute(self::NAMESPACE, '/comments', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleCommentsList'],
            'permission_callback' => static fn (): true => true,
        ]);
        self::registerRoute(self::NAMESPACE, '/comments/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleCommentGet'],
            'permission_callback' => static fn (): true => true,
        ]);

        // Users (public fields).
        self::registerRoute(self::NAMESPACE, '/users', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleUsersList'],
            'permission_callback' => static fn (): true => true,
        ]);
        self::registerRoute(self::NAMESPACE, '/users/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleUserGet'],
            'permission_callback' => static fn (): true => true,
        ]);

        // Taxonomies.
        self::registerRoute(self::NAMESPACE, '/categories', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleCategoriesList'],
            'permission_callback' => static fn (): true => true,
        ]);
        self::registerRoute(self::NAMESPACE, '/categories/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleCategoryGet'],
            'permission_callback' => static fn (): true => true,
        ]);
        self::registerRoute(self::NAMESPACE, '/tags', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleTagsList'],
            'permission_callback' => static fn (): true => true,
        ]);
        self::registerRoute(self::NAMESPACE, '/tags/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleTagGet'],
            'permission_callback' => static fn (): true => true,
        ]);

        // Forums (always registered; handlers 404 when module off).
        self::registerRoute(self::NAMESPACE, '/forums', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleForumsList'],
            'permission_callback' => static fn (): true => true,
        ]);
        self::registerRoute(self::NAMESPACE, '/forums/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleForumGet'],
            'permission_callback' => static fn (): true => true,
        ]);
        self::registerRoute(self::NAMESPACE, '/topics', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleTopicsList'],
            'permission_callback' => static fn (): true => true,
        ]);
        self::registerRoute(self::NAMESPACE, '/topics/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleTopicGet'],
            'permission_callback' => static fn (): true => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handleIndex(array $request): array
    {
        $db = self::reqDb($request);
        $namespaces = [self::NAMESPACE];
        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_rest_namespaces', $namespaces, $db);
            if (is_array($filtered)) {
                $namespaces = array_values(array_filter(array_map('strval', $filtered)));
            }
        }

        $routes = [];
        foreach (self::getRoutes() as $r) {
            $routes[$r['route']] = [
                'methods' => $r['methods'],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'name' => self::optionString('blogname', 'AgoraPress', $db),
                'description' => self::optionString('blogdescription', '', $db),
                'url' => self::optionString('home', '', $db),
                'home' => self::optionString('home', '', $db),
                'namespaces' => $namespaces,
                'authentication' => [
                    'cookie' => true,
                    'basic' => true,
                ],
                'routes' => $routes,
                'site_logo' => null,
                'timezone_string' => self::optionString('timezone_string', 'UTC', $db),
                'agorapress_version' => defined('AP_VERSION') ? (string) AP_VERSION : '',
                '_links' => [
                    'self' => [['href' => self::getUrl('/', $db)]],
                    self::NAMESPACE => [['href' => self::getUrl('/' . self::NAMESPACE, $db)]],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handleNamespaceIndex(array $request): array
    {
        $db = self::reqDb($request);
        $routes = [];
        $prefix = '/' . self::NAMESPACE;
        foreach (self::getRoutes() as $r) {
            if ($r['route'] === $prefix || str_starts_with($r['route'], $prefix . '/')) {
                $routes[$r['route']] = ['methods' => $r['methods']];
            }
        }

        return [
            'status' => 200,
            'data' => [
                'namespace' => self::NAMESPACE,
                'routes' => $routes,
                '_links' => [
                    'self' => [['href' => self::getUrl('/' . self::NAMESPACE, $db)]],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handleSettings(array $request): array
    {
        $db = self::reqDb($request);
        $modules = [
            'static_pages' => class_exists('AP_Options', false)
                ? AP_Options::isModuleEnabled(AP_Options::MODULE_STATIC_PAGES, $db)
                : true,
            'blog' => class_exists('AP_Options', false)
                ? AP_Options::isModuleEnabled(AP_Options::MODULE_BLOG, $db)
                : true,
            'forum' => class_exists('AP_Options', false)
                ? AP_Options::isModuleEnabled(AP_Options::MODULE_FORUM, $db)
                : false,
        ];

        return [
            'status' => 200,
            'data' => [
                'title' => self::optionString('blogname', 'AgoraPress', $db),
                'description' => self::optionString('blogdescription', '', $db),
                'url' => self::optionString('home', '', $db),
                'email' => '', // never expose admin_email publicly
                'timezone' => self::optionString('timezone_string', 'UTC', $db),
                'date_format' => self::optionString('date_format', 'Y-m-d', $db),
                'time_format' => self::optionString('time_format', 'H:i', $db),
                'language' => self::optionString('WPLANG', '', $db),
                'modules' => $modules,
                'users_can_register' => self::optionString('users_can_register', '0', $db) === '1',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handlePostsList(array $request): array
    {
        $db = self::reqDb($request);
        if (class_exists('AP_Options', false) && !AP_Options::isModuleEnabled(AP_Options::MODULE_BLOG, $db)) {
            return self::errorResponse('rest_module_disabled', 'Blog module is disabled.', 404);
        }

        $page = max(1, (int) ($request['params']['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request['params']['per_page'] ?? 10)));
        $search = trim((string) ($request['params']['search'] ?? ''));
        $author = (int) ($request['params']['author'] ?? 0);
        $order = strtoupper((string) ($request['params']['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $orderby = (string) ($request['params']['orderby'] ?? 'date');
        $orderbyMap = [
            'date' => 'post_date',
            'title' => 'post_title',
            'id' => 'ID',
            'modified' => 'post_modified',
            'slug' => 'post_name',
        ];
        $orderbyCol = $orderbyMap[$orderby] ?? 'post_date';

        $args = [
            'post_type' => 'post',
            'post_status' => self::visibleStatuses((int) $request['user_id'], 'post', $db),
            'orderby' => $orderbyCol,
            'order' => $order,
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];
        if ($author > 0) {
            $args['post_author'] = $author;
        }

        $posts = class_exists('AP_Post', false) ? AP_Post::query($args, $db) : [];
        if ($search !== '' && $posts !== []) {
            $needle = mb_strtolower($search);
            $posts = array_values(array_filter(
                $posts,
                static function (AP_Post $p) use ($needle): bool {
                    return str_contains(mb_strtolower($p->post_title), $needle)
                        || str_contains(mb_strtolower($p->post_content), $needle)
                        || str_contains(mb_strtolower($p->post_excerpt), $needle);
                }
            ));
        }

        $items = [];
        foreach ($posts as $post) {
            $items[] = self::preparePost($post, $db);
        }

        return [
            'status' => 200,
            'data' => $items,
            'headers' => [
                'X-AP-Total' => (string) count($items),
                'X-AP-Page' => (string) $page,
                'X-AP-Per-Page' => (string) $perPage,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handlePostGet(array $request): array
    {
        $db = self::reqDb($request);
        if (class_exists('AP_Options', false) && !AP_Options::isModuleEnabled(AP_Options::MODULE_BLOG, $db)) {
            return self::errorResponse('rest_module_disabled', 'Blog module is disabled.', 404);
        }

        $id = (int) ($request['params']['id'] ?? 0);
        $post = class_exists('AP_Post', false) ? AP_Post::get($id, $db) : null;
        if ($post === null || $post->post_type !== 'post') {
            return self::errorResponse('rest_post_invalid_id', 'Invalid post ID.', 404);
        }
        if (!self::canViewPost($post, (int) $request['user_id'], $db)) {
            return self::errorResponse('rest_forbidden', 'Sorry, you are not allowed to view this post.', 403);
        }

        return ['status' => 200, 'data' => self::preparePost($post, $db)];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}|array{code: string, message: string, status: int}
     */
    public static function handlePostCreate(array $request): array
    {
        $db = self::reqDb($request);
        if (class_exists('AP_Options', false) && !AP_Options::isModuleEnabled(AP_Options::MODULE_BLOG, $db)) {
            return self::errorResponse('rest_module_disabled', 'Blog module is disabled.', 404);
        }

        $nonceErr = self::requireWriteNonce($request);
        if ($nonceErr !== null) {
            return $nonceErr;
        }

        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $params = array_merge($body, is_array($request['params'] ?? null) ? $request['params'] : []);
        $title = trim((string) ($params['title'] ?? $params['post_title'] ?? ''));
        $content = (string) ($params['content'] ?? $params['post_content'] ?? '');
        $status = self::sanitizeKey((string) ($params['status'] ?? $params['post_status'] ?? 'draft'));
        if ($status === '') {
            $status = 'draft';
        }
        $userId = (int) $request['user_id'];

        if ($title === '' && $content === '') {
            return self::errorResponse('rest_missing_callback_param', 'Title or content is required.', 400);
        }

        // Contributors without publish_posts cannot publish.
        if (
            in_array($status, ['publish', 'future', 'private'], true)
            && class_exists('AP_Roles', false)
            && !AP_Roles::userCan($userId, 'publish_posts', null, $db)
        ) {
            $status = 'pending';
        }

        $data = [
            'post_title' => $title !== '' ? $title : 'Untitled',
            'post_content' => $content,
            'post_status' => $status,
            'post_type' => 'post',
            'post_author' => $userId,
            'post_excerpt' => (string) ($params['excerpt'] ?? $params['post_excerpt'] ?? ''),
        ];
        if (isset($params['slug']) || isset($params['post_name'])) {
            $data['post_name'] = (string) ($params['slug'] ?? $params['post_name']);
        }

        $id = AP_Post::insert($data, $db);
        if ($id < 1) {
            return self::errorResponse('rest_cannot_create', 'Could not create post.', 500);
        }
        $post = AP_Post::get($id, $db);
        if ($post === null) {
            return self::errorResponse('rest_cannot_create', 'Could not create post.', 500);
        }

        return ['status' => 201, 'data' => self::preparePost($post, $db)];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handlePostUpdate(array $request): array
    {
        $db = self::reqDb($request);
        $nonceErr = self::requireWriteNonce($request);
        if ($nonceErr !== null) {
            return $nonceErr;
        }

        $id = (int) ($request['params']['id'] ?? 0);
        $post = AP_Post::get($id, $db);
        if ($post === null || $post->post_type !== 'post') {
            return self::errorResponse('rest_post_invalid_id', 'Invalid post ID.', 404);
        }

        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $params = array_merge($body, is_array($request['params'] ?? null) ? $request['params'] : []);
        $data = [];
        if (array_key_exists('title', $params) || array_key_exists('post_title', $params)) {
            $data['post_title'] = (string) ($params['title'] ?? $params['post_title']);
        }
        if (array_key_exists('content', $params) || array_key_exists('post_content', $params)) {
            $data['post_content'] = (string) ($params['content'] ?? $params['post_content']);
        }
        if (array_key_exists('excerpt', $params) || array_key_exists('post_excerpt', $params)) {
            $data['post_excerpt'] = (string) ($params['excerpt'] ?? $params['post_excerpt']);
        }
        if (array_key_exists('status', $params) || array_key_exists('post_status', $params)) {
            $status = self::sanitizeKey((string) ($params['status'] ?? $params['post_status']));
            $userId = (int) $request['user_id'];
            if (
                in_array($status, ['publish', 'future', 'private'], true)
                && class_exists('AP_Roles', false)
                && !AP_Roles::userCan($userId, 'publish_posts', null, $db)
            ) {
                $status = 'pending';
            }
            $data['post_status'] = $status;
        }
        if (array_key_exists('slug', $params) || array_key_exists('post_name', $params)) {
            $data['post_name'] = (string) ($params['slug'] ?? $params['post_name']);
        }

        if ($data === []) {
            return ['status' => 200, 'data' => self::preparePost($post, $db)];
        }

        $ok = AP_Post::update($id, $data, $db);
        if (!$ok) {
            return self::errorResponse('rest_cannot_edit', 'Could not update post.', 500);
        }
        $updated = AP_Post::get($id, $db);

        return ['status' => 200, 'data' => self::preparePost($updated ?? $post, $db)];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handlePostDelete(array $request): array
    {
        $db = self::reqDb($request);
        $nonceErr = self::requireWriteNonce($request);
        if ($nonceErr !== null) {
            return $nonceErr;
        }

        $id = (int) ($request['params']['id'] ?? 0);
        $post = AP_Post::get($id, $db);
        if ($post === null || $post->post_type !== 'post') {
            return self::errorResponse('rest_post_invalid_id', 'Invalid post ID.', 404);
        }

        $force = !empty($request['params']['force'])
            || !empty($request['query']['force'])
            || (isset($request['body']['force']) && $request['body']['force']);

        $previous = self::preparePost($post, $db);
        if ($force) {
            $ok = AP_Post::delete($id, true, $db);
        } else {
            $ok = AP_Post::trash($id, $db);
        }
        if (!$ok) {
            return self::errorResponse('rest_cannot_delete', 'Could not delete post.', 500);
        }

        return [
            'status' => 200,
            'data' => [
                'deleted' => true,
                'previous' => $previous,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handlePagesList(array $request): array
    {
        $db = self::reqDb($request);
        if (
            class_exists('AP_Options', false)
            && !AP_Options::isModuleEnabled(AP_Options::MODULE_STATIC_PAGES, $db)
        ) {
            return self::errorResponse('rest_module_disabled', 'Static Pages module is disabled.', 404);
        }

        $page = max(1, (int) ($request['params']['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request['params']['per_page'] ?? 10)));
        $args = [
            'post_type' => 'page',
            'post_status' => self::visibleStatuses((int) $request['user_id'], 'page', $db),
            'orderby' => 'menu_order',
            'order' => 'ASC',
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];
        $posts = class_exists('AP_Post', false) ? AP_Post::query($args, $db) : [];
        $items = [];
        foreach ($posts as $post) {
            $items[] = self::preparePost($post, $db);
        }

        return ['status' => 200, 'data' => $items];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handlePageGet(array $request): array
    {
        $db = self::reqDb($request);
        if (
            class_exists('AP_Options', false)
            && !AP_Options::isModuleEnabled(AP_Options::MODULE_STATIC_PAGES, $db)
        ) {
            return self::errorResponse('rest_module_disabled', 'Static Pages module is disabled.', 404);
        }

        $id = (int) ($request['params']['id'] ?? 0);
        $post = class_exists('AP_Post', false) ? AP_Post::get($id, $db) : null;
        if ($post === null || $post->post_type !== 'page') {
            return self::errorResponse('rest_post_invalid_id', 'Invalid page ID.', 404);
        }
        if (!self::canViewPost($post, (int) $request['user_id'], $db)) {
            return self::errorResponse('rest_forbidden', 'Sorry, you are not allowed to view this page.', 403);
        }

        return ['status' => 200, 'data' => self::preparePost($post, $db)];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handleCommentsList(array $request): array
    {
        $db = self::reqDb($request);
        if (!class_exists('AP_Comment', false)) {
            return ['status' => 200, 'data' => []];
        }

        $page = max(1, (int) ($request['params']['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request['params']['per_page'] ?? 10)));
        $postId = (int) ($request['params']['post'] ?? $request['params']['post_id'] ?? 0);

        $args = [
            'status' => 'approve',
            'number' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'orderby' => 'comment_date_gmt',
            'order' => 'DESC',
        ];
        if ($postId > 0) {
            $args['post_id'] = $postId;
        }

        $comments = AP_Comment::query($args, $db);
        $items = [];
        foreach ($comments as $c) {
            $items[] = self::prepareComment($c, $db);
        }

        return ['status' => 200, 'data' => $items];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handleCommentGet(array $request): array
    {
        $db = self::reqDb($request);
        $id = (int) ($request['params']['id'] ?? 0);
        $comment = class_exists('AP_Comment', false) ? AP_Comment::get($id, $db) : null;
        if ($comment === null) {
            return self::errorResponse('rest_comment_invalid_id', 'Invalid comment ID.', 404);
        }
        // Only approved for public; moderators may see others later.
        $status = (string) ($comment->comment_approved ?? '');
        if ($status !== '1' && $status !== 'approved') {
            $userId = (int) $request['user_id'];
            $canMod = $userId > 0
                && class_exists('AP_Roles', false)
                && AP_Roles::userCan($userId, 'moderate_comments', null, $db);
            if (!$canMod) {
                return self::errorResponse('rest_forbidden', 'Sorry, you are not allowed to view this comment.', 403);
            }
        }

        return ['status' => 200, 'data' => self::prepareComment($comment, $db)];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handleUsersList(array $request): array
    {
        $db = self::reqDb($request);
        if (!class_exists('AP_User', false)) {
            return ['status' => 200, 'data' => []];
        }

        $page = max(1, (int) ($request['params']['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request['params']['per_page'] ?? 10)));
        $users = AP_User::query([
            'number' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'orderby' => 'ID',
            'order' => 'ASC',
        ], $db);

        $items = [];
        foreach ($users as $user) {
            $items[] = self::prepareUser($user, (int) $request['user_id'], $db);
        }

        return ['status' => 200, 'data' => $items];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handleUserGet(array $request): array
    {
        $db = self::reqDb($request);
        $id = (int) ($request['params']['id'] ?? 0);
        $user = class_exists('AP_User', false) ? AP_User::getById($id, $db) : null;
        if ($user === null) {
            return self::errorResponse('rest_user_invalid_id', 'Invalid user ID.', 404);
        }

        return ['status' => 200, 'data' => self::prepareUser($user, (int) $request['user_id'], $db)];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handleCategoriesList(array $request): array
    {
        return self::handleTermsList($request, 'category');
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handleCategoryGet(array $request): array
    {
        return self::handleTermGet($request, 'category');
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handleTagsList(array $request): array
    {
        return self::handleTermsList($request, 'post_tag');
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handleTagGet(array $request): array
    {
        return self::handleTermGet($request, 'post_tag');
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    private static function handleTermsList(array $request, string $taxonomy): array
    {
        $db = self::reqDb($request);
        if (!class_exists('AP_Taxonomy', false)) {
            return ['status' => 200, 'data' => []];
        }
        $perPage = min(100, max(1, (int) ($request['params']['per_page'] ?? 100)));
        $page = max(1, (int) ($request['params']['page'] ?? 1));
        $terms = AP_Taxonomy::getTerms($taxonomy, [
            'number' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'hide_empty' => !empty($request['params']['hide_empty']),
            'orderby' => 'name',
            'order' => 'ASC',
        ], $db);
        $items = [];
        foreach ($terms as $term) {
            $items[] = self::prepareTerm($term, $taxonomy, $db);
        }

        return ['status' => 200, 'data' => $items];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    private static function handleTermGet(array $request, string $taxonomy): array
    {
        $db = self::reqDb($request);
        $id = (int) ($request['params']['id'] ?? 0);
        $term = class_exists('AP_Taxonomy', false) ? AP_Taxonomy::getTerm($id, $taxonomy, $db) : null;
        if ($term === null) {
            return self::errorResponse('rest_term_invalid', 'Invalid term ID.', 404);
        }

        return ['status' => 200, 'data' => self::prepareTerm($term, $taxonomy, $db)];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handleForumsList(array $request): array
    {
        $db = self::reqDb($request);
        if (!self::forumModuleOn($db)) {
            return self::errorResponse('rest_module_disabled', 'Forum module is disabled.', 404);
        }
        if (!class_exists('AP_Forum', false)) {
            return ['status' => 200, 'data' => []];
        }

        $forums = AP_Forum::getForums(['status' => 'open'], $db);
        $items = [];
        foreach ($forums as $forum) {
            $items[] = self::prepareForum($forum, $db);
        }

        return ['status' => 200, 'data' => $items];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handleForumGet(array $request): array
    {
        $db = self::reqDb($request);
        if (!self::forumModuleOn($db)) {
            return self::errorResponse('rest_module_disabled', 'Forum module is disabled.', 404);
        }
        $id = (int) ($request['params']['id'] ?? 0);
        $forum = class_exists('AP_Forum', false) ? AP_Forum::getForum($id, $db) : null;
        if ($forum === null) {
            return self::errorResponse('rest_forum_invalid_id', 'Invalid forum ID.', 404);
        }

        return ['status' => 200, 'data' => self::prepareForum($forum, $db)];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handleTopicsList(array $request): array
    {
        $db = self::reqDb($request);
        if (!self::forumModuleOn($db)) {
            return self::errorResponse('rest_module_disabled', 'Forum module is disabled.', 404);
        }
        if (!class_exists('AP_Forum', false)) {
            return ['status' => 200, 'data' => []];
        }

        $forumId = (int) ($request['params']['forum'] ?? $request['params']['forum_id'] ?? 0);
        $page = max(1, (int) ($request['params']['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request['params']['per_page'] ?? 20)));
        if ($forumId > 0) {
            $topics = AP_Forum::getTopics($forumId, [
                'per_page' => $perPage,
                'page' => $page,
                'status' => 'open',
            ], $db);
        } elseif (method_exists('AP_Forum', 'queryTopics')) {
            $topics = AP_Forum::queryTopics([
                'per_page' => $perPage,
                'page' => $page,
                'approved_only' => true,
            ], $db);
        } else {
            $topics = [];
        }
        $items = [];
        foreach ($topics as $topic) {
            $items[] = self::prepareTopic($topic, $db);
        }

        return ['status' => 200, 'data' => $items];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}
     */
    public static function handleTopicGet(array $request): array
    {
        $db = self::reqDb($request);
        if (!self::forumModuleOn($db)) {
            return self::errorResponse('rest_module_disabled', 'Forum module is disabled.', 404);
        }
        $id = (int) ($request['params']['id'] ?? 0);
        $topic = class_exists('AP_Forum', false) ? AP_Forum::getTopic($id, $db) : null;
        if ($topic === null) {
            return self::errorResponse('rest_topic_invalid_id', 'Invalid topic ID.', 404);
        }

        return ['status' => 200, 'data' => self::prepareTopic($topic, $db)];
    }

    // -------------------------------------------------------------------------
    // Permissions
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $request
     *
     * @return true|array{code: string, message: string, status: int}
     */
    public static function permCreatePost(array $request): true|array
    {
        $userId = (int) ($request['user_id'] ?? 0);
        if ($userId < 1) {
            return ['code' => 'rest_not_logged_in', 'message' => 'You are not currently logged in.', 'status' => 401];
        }
        $db = self::reqDb($request);
        if (class_exists('AP_Roles', false) && !AP_Roles::userCan($userId, 'edit_posts', null, $db)) {
            return ['code' => 'rest_cannot_create', 'message' => 'Sorry, you are not allowed to create posts.', 'status' => 403];
        }

        return true;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return true|array{code: string, message: string, status: int}
     */
    public static function permEditPost(array $request): true|array
    {
        $userId = (int) ($request['user_id'] ?? 0);
        if ($userId < 1) {
            return ['code' => 'rest_not_logged_in', 'message' => 'You are not currently logged in.', 'status' => 401];
        }
        $db = self::reqDb($request);
        $id = (int) ($request['params']['id'] ?? 0);
        if (class_exists('AP_Roles', false) && !AP_Roles::userCan($userId, 'edit_post', $id, $db)) {
            // Fall back to primitive if meta-cap signature differs.
            if (!AP_Roles::userCan($userId, 'edit_posts', null, $db)) {
                return ['code' => 'rest_cannot_edit', 'message' => 'Sorry, you are not allowed to edit this post.', 'status' => 403];
            }
            $post = class_exists('AP_Post', false) ? AP_Post::get($id, $db) : null;
            if ($post === null) {
                return ['code' => 'rest_post_invalid_id', 'message' => 'Invalid post ID.', 'status' => 404];
            }
            $canOthers = AP_Roles::userCan($userId, 'edit_others_posts', null, $db);
            if ((int) $post->post_author !== $userId && !$canOthers) {
                return ['code' => 'rest_cannot_edit', 'message' => 'Sorry, you are not allowed to edit this post.', 'status' => 403];
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return true|array{code: string, message: string, status: int}
     */
    public static function permDeletePost(array $request): true|array
    {
        $userId = (int) ($request['user_id'] ?? 0);
        if ($userId < 1) {
            return ['code' => 'rest_not_logged_in', 'message' => 'You are not currently logged in.', 'status' => 401];
        }
        $db = self::reqDb($request);
        $id = (int) ($request['params']['id'] ?? 0);
        $post = class_exists('AP_Post', false) ? AP_Post::get($id, $db) : null;
        if ($post === null) {
            return ['code' => 'rest_post_invalid_id', 'message' => 'Invalid post ID.', 'status' => 404];
        }
        if (class_exists('AP_Roles', false)) {
            $canDelete = AP_Roles::userCan($userId, 'delete_posts', null, $db);
            $canOthers = AP_Roles::userCan($userId, 'delete_others_posts', null, $db);
            if (!$canDelete) {
                return ['code' => 'rest_cannot_delete', 'message' => 'Sorry, you are not allowed to delete this post.', 'status' => 403];
            }
            if ((int) $post->post_author !== $userId && !$canOthers) {
                return ['code' => 'rest_cannot_delete', 'message' => 'Sorry, you are not allowed to delete this post.', 'status' => 403];
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Serializers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function preparePost(AP_Post $post, ?AP_DB $db = null): array
    {
        $link = '';
        if (class_exists('AP_Rewrite', false)) {
            $link = AP_Rewrite::getPermalink($post, $db);
        }

        $data = [
            'id' => (int) $post->ID,
            'date' => self::localDate($post->post_date),
            'date_gmt' => self::gmtDate($post->post_date_gmt, $post->post_date),
            'modified' => self::localDate($post->post_modified),
            'modified_gmt' => self::gmtDate($post->post_modified_gmt, $post->post_modified),
            'slug' => (string) $post->post_name,
            'status' => (string) $post->post_status,
            'type' => (string) $post->post_type,
            'link' => $link,
            'title' => [
                'raw' => (string) $post->post_title,
                'rendered' => self::escapeHtml((string) $post->post_title),
            ],
            'content' => [
                'raw' => (string) $post->post_content,
                'rendered' => self::renderContent((string) $post->post_content),
            ],
            'excerpt' => [
                'raw' => (string) $post->post_excerpt,
                'rendered' => self::escapeHtml((string) $post->post_excerpt),
            ],
            'author' => (int) $post->post_author,
            'parent' => (int) $post->post_parent,
            'menu_order' => (int) $post->menu_order,
            'comment_status' => (string) $post->comment_status,
            'comment_count' => (int) $post->comment_count,
            'password_protected' => $post->post_password !== '',
        ];

        // Never expose password hash/value.
        if ($post->post_password !== '') {
            $data['content']['raw'] = '';
            $data['content']['rendered'] = '';
            $data['excerpt']['raw'] = '';
            $data['excerpt']['rendered'] = '';
        }

        if (function_exists('ap_apply_filters')) {
            $data = ap_apply_filters('ap_rest_prepare_post', $data, $post, $db);
            if (!is_array($data)) {
                $data = [];
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function prepareComment(object $comment, ?AP_DB $db = null): array
    {
        $id = (int) ($comment->comment_ID ?? $comment->ID ?? 0);
        $content = (string) ($comment->comment_content ?? '');

        return [
            'id' => $id,
            'post' => (int) ($comment->comment_post_ID ?? 0),
            'parent' => (int) ($comment->comment_parent ?? 0),
            'author' => (int) ($comment->user_id ?? 0),
            'author_name' => (string) ($comment->comment_author ?? ''),
            'date' => self::localDate((string) ($comment->comment_date ?? '')),
            'date_gmt' => self::gmtDate(
                (string) ($comment->comment_date_gmt ?? ''),
                (string) ($comment->comment_date ?? '')
            ),
            'content' => [
                'raw' => $content,
                'rendered' => self::escapeHtml($content),
            ],
            'status' => self::mapCommentStatus((string) ($comment->comment_approved ?? '')),
            'type' => (string) ($comment->comment_type ?? 'comment'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function prepareUser(AP_User $user, int $viewerId = 0, ?AP_DB $db = null): array
    {
        $data = [
            'id' => (int) $user->ID,
            'name' => (string) ($user->display_name !== '' ? $user->display_name : $user->user_login),
            'url' => (string) ($user->user_url ?? ''),
            'description' => '',
            'slug' => (string) ($user->user_nicename ?? ''),
            'avatar_urls' => [],
        ];

        if (class_exists('AP_User', false) && method_exists('AP_User', 'getProfileMeta')) {
            $profile = AP_User::getProfileMeta((int) $user->ID, $db);
            if (is_array($profile) && isset($profile['description'])) {
                $data['description'] = (string) $profile['description'];
            }
        }

        // Email only for self or users with list_users.
        $canSeeEmail = $viewerId > 0 && (
            $viewerId === (int) $user->ID
            || (class_exists('AP_Roles', false) && AP_Roles::userCan($viewerId, 'list_users', null, $db))
        );
        if ($canSeeEmail) {
            $data['email'] = (string) ($user->user_email ?? '');
        }

        if (class_exists('AP_Avatar', false) && function_exists('ap_get_avatar_url')) {
            foreach ([24, 48, 96] as $size) {
                $data['avatar_urls'][(string) $size] = (string) ap_get_avatar_url($user->ID, $size, [], $db);
            }
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_rest_prepare_user', $data, $user, $viewerId, $db);
            if (is_array($filtered)) {
                $data = $filtered;
            }
        }

        return $data;
    }

    /**
     * @param object|array<string, mixed> $term
     *
     * @return array<string, mixed>
     */
    public static function prepareTerm(object|array $term, string $taxonomy, ?AP_DB $db = null): array
    {
        if (is_array($term)) {
            $term = (object) $term;
        }
        $id = (int) ($term->term_id ?? 0);
        $link = '';
        if (class_exists('AP_Rewrite', false) && method_exists('AP_Rewrite', 'getTermLink')) {
            try {
                $link = (string) AP_Rewrite::getTermLink($id, $taxonomy, $db);
            } catch (Throwable) {
                $link = '';
            }
        }

        return [
            'id' => $id,
            'count' => (int) ($term->count ?? 0),
            'description' => (string) ($term->description ?? ''),
            'link' => $link,
            'name' => (string) ($term->name ?? ''),
            'slug' => (string) ($term->slug ?? ''),
            'taxonomy' => $taxonomy,
            'parent' => (int) ($term->parent ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function prepareForum(object $forum, ?AP_DB $db = null): array
    {
        $id = (int) ($forum->forum_id ?? $forum->id ?? 0);
        $link = '';
        if (class_exists('AP_Forum', false) && method_exists('AP_Forum', 'forumUrl')) {
            try {
                $link = (string) AP_Forum::forumUrl($id);
            } catch (Throwable) {
                $link = '';
            }
        }

        return [
            'id' => $id,
            'name' => (string) ($forum->forum_name ?? $forum->name ?? ''),
            'slug' => (string) ($forum->forum_slug ?? $forum->slug ?? ''),
            'description' => (string) ($forum->forum_desc ?? $forum->description ?? ''),
            'parent' => (int) ($forum->parent_id ?? 0),
            'type' => (string) ($forum->forum_type ?? $forum->type ?? 'forum'),
            'status' => (string) ($forum->forum_status ?? $forum->status ?? 'open'),
            'topic_count' => (int) ($forum->topic_count ?? 0),
            'post_count' => (int) ($forum->post_count ?? 0),
            'link' => $link,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function prepareTopic(object $topic, ?AP_DB $db = null): array
    {
        $id = (int) ($topic->topic_id ?? $topic->id ?? 0);
        $link = '';
        if (class_exists('AP_Forum', false) && method_exists('AP_Forum', 'topicUrl')) {
            try {
                $link = (string) AP_Forum::topicUrl($id);
            } catch (Throwable) {
                $link = '';
            }
        }

        return [
            'id' => $id,
            'forum' => (int) ($topic->forum_id ?? 0),
            'title' => (string) ($topic->topic_title ?? $topic->title ?? ''),
            'slug' => (string) ($topic->topic_slug ?? $topic->slug ?? ''),
            'status' => (string) ($topic->topic_status ?? $topic->status ?? 'open'),
            'type' => (string) ($topic->topic_type ?? $topic->type ?? 'standard'),
            'author' => (int) ($topic->topic_poster ?? $topic->poster_id ?? 0),
            'post_count' => (int) ($topic->post_count ?? $topic->reply_count ?? 0),
            'views' => (int) ($topic->topic_views ?? $topic->views ?? 0),
            'link' => $link,
            'date' => self::localDate((string) ($topic->topic_time ?? $topic->created_at ?? '')),
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $request
     */
    private static function reqDb(array $request): ?AP_DB
    {
        $db = $request['db'] ?? null;

        return $db instanceof AP_DB ? $db : null;
    }

    private static function forumModuleOn(?AP_DB $db): bool
    {
        if (!class_exists('AP_Options', false)) {
            return true;
        }

        return AP_Options::isModuleEnabled(AP_Options::MODULE_FORUM, $db);
    }

    /**
     * @return list<string>
     */
    private static function visibleStatuses(int $userId, string $type, ?AP_DB $db): array
    {
        $statuses = ['publish'];
        if ($userId < 1 || !class_exists('AP_Roles', false)) {
            return $statuses;
        }
        $cap = $type === 'page' ? 'edit_pages' : 'edit_posts';
        if (AP_Roles::userCan($userId, $cap, null, $db)) {
            $statuses = ['publish', 'draft', 'pending', 'private', 'future'];
        }

        return $statuses;
    }

    private static function canViewPost(AP_Post $post, int $userId, ?AP_DB $db): bool
    {
        if ($post->post_status === 'publish' && $post->post_password === '') {
            return true;
        }
        if ($userId < 1) {
            return false;
        }
        if ((int) $post->post_author === $userId) {
            return true;
        }
        if (class_exists('AP_Roles', false)) {
            $cap = $post->post_type === 'page' ? 'edit_others_pages' : 'edit_others_posts';
            if (AP_Roles::userCan($userId, $cap, null, $db)) {
                return true;
            }
            if (AP_Roles::userCan($userId, 'edit_posts', null, $db) && $post->post_status !== 'private') {
                return AP_Roles::userCan($userId, 'read_private_posts', null, $db)
                    || $post->post_status !== 'private';
            }
        }

        return false;
    }

    /**
     * Cookie or Basic auth → user id.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $server
     */
    public static function authenticate(array $headers, array $server = [], ?AP_DB $db = null): int
    {
        // HTTP Basic first (explicit API credentials).
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if ($auth === '' && isset($server['HTTP_AUTHORIZATION']) && is_string($server['HTTP_AUTHORIZATION'])) {
            $auth = $server['HTTP_AUTHORIZATION'];
        }
        if ($auth === '' && isset($server['REDIRECT_HTTP_AUTHORIZATION']) && is_string($server['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth = $server['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if ($auth === '' && isset($server['PHP_AUTH_USER']) && is_string($server['PHP_AUTH_USER'])) {
            $user = $server['PHP_AUTH_USER'];
            $pass = isset($server['PHP_AUTH_PW']) && is_string($server['PHP_AUTH_PW'])
                ? $server['PHP_AUTH_PW']
                : '';
            if ($user !== '' && class_exists('AP_User', false)) {
                $u = AP_User::authenticate($user, $pass, $db);
                if ($u !== null) {
                    return (int) $u->ID;
                }
            }
        } elseif (preg_match('/^Basic\s+(.+)$/i', $auth, $m) === 1) {
            $decoded = base64_decode($m[1], true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$user, $pass] = explode(':', $decoded, 2);
                if ($user !== '' && class_exists('AP_User', false)) {
                    $u = AP_User::authenticate($user, $pass, $db);
                    if ($u !== null) {
                        return (int) $u->ID;
                    }
                }
            }
        }

        // Session cookie.
        if (class_exists('AP_Session', false)) {
            try {
                $current = AP_Session::getCurrentUser($db);
                if ($current instanceof AP_User) {
                    return (int) $current->ID;
                }
            } catch (Throwable) {
                // ignore
            }
        }
        if (function_exists('ap_get_current_user_id')) {
            return max(0, (int) ap_get_current_user_id($db));
        }

        return 0;
    }

    /**
     * For cookie-auth write requests, require a valid REST nonce.
     * Basic-auth requests skip the nonce (credentials are the proof).
     *
     * @param array<string, mixed> $request
     *
     * @return array{status: int, data: mixed}|null Error response or null if ok.
     */
    private static function requireWriteNonce(array $request): ?array
    {
        $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
        $server = is_array($request['server'] ?? null) ? $request['server'] : [];
        $usedBasic = self::requestUsedBasicAuth($headers, $server);
        if ($usedBasic) {
            return null;
        }

        // Tests / programmatic dispatch with explicit user_id and no server may skip.
        if ($server === [] && $headers === [] && (int) ($request['user_id'] ?? 0) > 0) {
            // Allow when caller already set user_id without browser context
            // (unit tests, CLI). Still prefer nonce when provided.
            $nonce = self::extractNonce($request);
            if ($nonce === '') {
                return null;
            }
        }

        $nonce = self::extractNonce($request);
        if ($nonce === '') {
            // Browser cookie sessions must send a nonce; pure programmatic
            // with user_id already resolved is allowed when no cookie headers.
            if (!self::requestHasCookieAuth($headers, $server)) {
                return null;
            }

            return self::errorResponse(
                'rest_cookie_invalid_nonce',
                'Cookie authentication requires a valid nonce (X-AP-Nonce header).',
                403
            );
        }

        if (!class_exists('AP_Nonce', false) && !function_exists('ap_check_nonce')) {
            return null;
        }

        $userId = (int) ($request['user_id'] ?? 0);
        $ok = function_exists('ap_check_nonce')
            ? ap_check_nonce($nonce, self::NONCE_ACTION, $userId > 0 ? $userId : null)
            : AP_Nonce::verify($nonce, self::NONCE_ACTION, $userId > 0 ? $userId : null);

        if (!$ok) {
            return self::errorResponse(
                'rest_cookie_invalid_nonce',
                'Cookie check failed: invalid nonce.',
                403
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function extractNonce(array $request): string
    {
        $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
        foreach (['X-AP-Nonce', 'X-Ap-Nonce', 'X-WP-Nonce', 'x-ap-nonce'] as $h) {
            if (isset($headers[$h]) && is_string($headers[$h]) && $headers[$h] !== '') {
                return $headers[$h];
            }
        }
        // Case-insensitive scan.
        foreach ($headers as $name => $value) {
            if (is_string($name) && strcasecmp($name, 'X-AP-Nonce') === 0 && is_string($value)) {
                return $value;
            }
        }
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];
        if (isset($params['_ap_nonce']) && is_string($params['_ap_nonce'])) {
            return $params['_ap_nonce'];
        }
        if (isset($params['_wpnonce']) && is_string($params['_wpnonce'])) {
            return $params['_wpnonce'];
        }
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        if (isset($body['_ap_nonce']) && is_string($body['_ap_nonce'])) {
            return $body['_ap_nonce'];
        }

        return '';
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $server
     */
    private static function requestUsedBasicAuth(array $headers, array $server): bool
    {
        if (isset($server['PHP_AUTH_USER']) && is_string($server['PHP_AUTH_USER']) && $server['PHP_AUTH_USER'] !== '') {
            return true;
        }
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if ($auth === '' && isset($server['HTTP_AUTHORIZATION'])) {
            $auth = (string) $server['HTTP_AUTHORIZATION'];
        }

        return is_string($auth) && preg_match('/^Basic\s+/i', $auth) === 1;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $server
     */
    private static function requestHasCookieAuth(array $headers, array $server): bool
    {
        if (isset($server['HTTP_COOKIE']) && is_string($server['HTTP_COOKIE']) && $server['HTTP_COOKIE'] !== '') {
            return true;
        }
        foreach ($headers as $name => $value) {
            if (is_string($name) && strcasecmp($name, 'Cookie') === 0 && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{definition: array<string, mixed>, params: array<string, string>}|null
     */
    private static function matchRoute(string $method, string $route): ?array
    {
        $route = self::normalizeRoute($route);
        foreach (self::$routes as $def) {
            if (!in_array($method, $def['methods'], true)) {
                continue;
            }
            $pattern = $def['pattern'] ?? self::routeToPattern($def['route']);
            if (@preg_match($pattern, $route, $matches) !== 1) {
                continue;
            }
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = (string) $value;
                }
            }

            return ['definition' => $def, 'params' => $params];
        }

        return null;
    }

    private static function routeToPattern(string $route): string
    {
        $route = self::normalizeRoute($route);
        // Convert (?P<name>…) segments; escape the rest.
        $parts = preg_split('/(\(\?P<[^>]+>[^)]+\))/', $route, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            $parts = [$route];
        }
        $pattern = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (str_starts_with($part, '(?P<')) {
                $pattern .= $part;
            } else {
                $pattern .= preg_quote($part, '#');
            }
        }

        return '#^' . $pattern . '$#';
    }

    private static function normalizeRoute(string $route): string
    {
        $route = trim($route);
        if ($route === '') {
            return '/';
        }
        if ($route[0] !== '/') {
            $route = '/' . $route;
        }
        // Strip trailing slash except root.
        if (strlen($route) > 1) {
            $route = rtrim($route, '/');
        }

        return $route;
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            if (!is_string($k) || (!is_string($v) && !is_numeric($v))) {
                continue;
            }
            $out[$k] = (string) $v;
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string>            $headers
     *
     * @return array<string, mixed>
     */
    private static function parseBody(mixed $body, array $headers): array
    {
        if (is_array($body)) {
            return $body;
        }
        if (!is_string($body) || $body === '') {
            return [];
        }
        $ct = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
        if (is_string($ct) && str_contains(strtolower($ct), 'application/json')) {
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : [];
        }
        // Try JSON anyway.
        $trimmed = ltrim($body);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $parsed = [];
        parse_str($body, $parsed);

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param mixed $result
     *
     * @return array{status: int, data: mixed, headers: array<string, string>}
     */
    private static function normalizeResult(mixed $result): array
    {
        if (is_array($result) && isset($result['status'], $result['data'])) {
            $headers = [];
            if (isset($result['headers']) && is_array($result['headers'])) {
                foreach ($result['headers'] as $k => $v) {
                    if (is_string($k) && (is_string($v) || is_numeric($v))) {
                        $headers[$k] = (string) $v;
                    }
                }
            }

            return [
                'status' => (int) $result['status'],
                'data' => $result['data'],
                'headers' => $headers,
            ];
        }

        // WP-style error: {code, message, data: {status}}
        if (is_array($result) && isset($result['code'], $result['message'])) {
            $status = 400;
            if (isset($result['status'])) {
                $status = (int) $result['status'];
            } elseif (isset($result['data']['status'])) {
                $status = (int) $result['data']['status'];
            }

            return self::errorResponse((string) $result['code'], (string) $result['message'], $status);
        }

        return [
            'status' => 200,
            'data' => $result,
            'headers' => [],
        ];
    }

    /**
     * @return array{status: int, data: array<string, mixed>, headers: array<string, string>}
     */
    public static function errorResponse(string $code, string $message, int $status = 400): array
    {
        return [
            'status' => $status,
            'data' => [
                'code' => $code,
                'message' => $message,
                'data' => ['status' => $status],
            ],
            'headers' => [],
        ];
    }

    /**
     * @return array{status: int, data: mixed, headers: array<string, string>}
     */
    private static function optionsResponse(string $route): array
    {
        $methods = ['OPTIONS'];
        foreach (self::$routes as $def) {
            $pattern = $def['pattern'] ?? self::routeToPattern($def['route']);
            if (@preg_match($pattern, self::normalizeRoute($route)) === 1) {
                foreach ($def['methods'] as $m) {
                    $methods[] = $m;
                }
            }
        }
        $methods = array_values(array_unique($methods));

        return [
            'status' => 200,
            'data' => null,
            'headers' => [
                'Allow' => implode(', ', $methods),
                'Access-Control-Allow-Methods' => implode(', ', $methods),
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-AP-Nonce',
            ],
        ];
    }

    private static function optionString(string $name, string $default, ?AP_DB $db): string
    {
        if (class_exists('AP_Options', false)) {
            $v = AP_Options::get($name, $default, $db);

            return is_string($v) || is_numeric($v) ? (string) $v : $default;
        }

        return $default;
    }

    private static function localDate(string $mysql): string
    {
        $mysql = trim($mysql);
        if ($mysql === '' || str_starts_with($mysql, '0000')) {
            return '';
        }
        // Already ISO-ish.
        return str_replace(' ', 'T', $mysql);
    }

    private static function gmtDate(string $gmt, string $fallbackLocal): string
    {
        $gmt = trim($gmt);
        if ($gmt !== '' && !str_starts_with($gmt, '0000')) {
            return str_replace(' ', 'T', $gmt);
        }
        $local = self::localDate($fallbackLocal);

        return $local !== '' ? $local : '';
    }

    private static function escapeHtml(string $text): string
    {
        if (function_exists('ap_esc_html')) {
            return ap_esc_html($text);
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function renderContent(string $content): string
    {
        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_the_content', $content);
            if (is_string($filtered)) {
                return $filtered;
            }
        }
        if (class_exists('AP_Content_Format', false)) {
            return AP_Content_Format::format($content);
        }

        return nl2br(self::escapeHtml($content), false);
    }

    private static function mapCommentStatus(string $status): string
    {
        return match ($status) {
            '1', 'approved' => 'approved',
            '0', 'hold', 'pending' => 'hold',
            'spam' => 'spam',
            'trash' => 'trash',
            default => $status !== '' ? $status : 'hold',
        };
    }

    private static function sanitizeKey(string $key): string
    {
        $key = strtolower($key);

        return (string) preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}
