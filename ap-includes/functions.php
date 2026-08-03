<?php

/**
 * AgoraPress core helper functions.
 *
 * Hybrid procedural surface familiar to classic WordPress developers.
 * Class implementations live in class-ap-*.php; thin ap_* wrappers here.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Hash a password with Argon2id when available.
 *
 * @see AP_User::hashPassword()
 */
function ap_hash_password(string $password): string
{
    return AP_User::hashPassword($password);
}

/**
 * Verify a plain password against a stored hash.
 *
 * @see AP_User::checkPassword()
 */
function ap_check_password(string $password, string $hash): bool
{
    return AP_User::checkPassword($password, $hash);
}

/**
 * Whether a stored hash should be upgraded to the preferred algorithm.
 *
 * @see AP_User::passwordNeedsRehash()
 */
function ap_password_needs_rehash(string $hash): bool
{
    return AP_User::passwordNeedsRehash($hash);
}

/**
 * Authenticate a user by login or email + password.
 *
 * Returns an AP_User on success, or null on failure. Does not start a
 * session or set cookies — use {@see ap_login()} for that.
 *
 * @see AP_User::authenticate()
 */
function ap_authenticate(string $loginOrEmail, string $password, ?AP_DB $db = null): ?AP_User
{
    return AP_User::authenticate($loginOrEmail, $password, $db);
}

/**
 * Load a user by field: id | login | email | slug.
 *
 * @see AP_User::getBy()
 */
function ap_get_user_by(string $field, string|int $value, ?AP_DB $db = null): ?AP_User
{
    return AP_User::getBy($field, $value, $db);
}

/**
 * Authenticate and establish a signed session cookie on success.
 *
 * @see AP_Session::login()
 */
function ap_login(
    string $loginOrEmail,
    string $password,
    bool $remember = false,
    ?AP_DB $db = null
): ?AP_User {
    return AP_Session::login($loginOrEmail, $password, $remember, $db);
}

/**
 * Destroy the current session token and clear the auth cookie.
 *
 * @see AP_Session::logout()
 */
function ap_logout(?AP_DB $db = null): void
{
    AP_Session::logout($db);
}

/**
 * Issue a session cookie for an already-authenticated user id.
 *
 * @see AP_Session::setAuthCookie()
 */
function ap_set_auth_cookie(int $userId, bool $remember = false, ?AP_DB $db = null): bool
{
    return AP_Session::setAuthCookie($userId, $remember, $db);
}

/**
 * Clear the auth cookie in the browser (does not revoke other devices).
 *
 * @see AP_Session::clearAuthCookie()
 */
function ap_clear_auth_cookie(): void
{
    AP_Session::clearAuthCookie();
}

/**
 * Whether the current request is logged in via a valid auth cookie.
 *
 * @see AP_Session::isLoggedIn()
 */
function ap_is_user_logged_in(?AP_DB $db = null): bool
{
    return AP_Session::isLoggedIn($db);
}

/**
 * Current user id from a valid auth cookie, or 0.
 *
 * @see AP_Session::getCurrentUserId()
 */
function ap_get_current_user_id(?AP_DB $db = null): int
{
    return AP_Session::getCurrentUserId($db);
}

/**
 * Current AP_User from a valid auth cookie, or null when guest.
 *
 * @see AP_Session::getCurrentUser()
 */
function ap_get_current_user(?AP_DB $db = null): ?AP_User
{
    return AP_Session::getCurrentUser($db);
}

/**
 * Name of the logged-in auth cookie for this install.
 *
 * @see AP_Session::cookieName()
 */
function ap_auth_cookie_name(): string
{
    return AP_Session::cookieName();
}

/**
 * Revoke every session token for a user (e.g. after password change).
 *
 * @see AP_Session::destroyAllSessionTokens()
 */
function ap_destroy_user_sessions(int $userId, ?AP_DB $db = null): void
{
    AP_Session::destroyAllSessionTokens($userId, $db);
}

// -----------------------------------------------------------------------------
// Posts — statuses, types, CRUD, hierarchy
// -----------------------------------------------------------------------------

/**
 * Register a post status.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Post::registerStatus()
 */
function ap_register_post_status(string $status, array $args = []): void
{
    AP_Post::registerStatus($status, $args);
}

/**
 * Registered post status object, or null.
 *
 * @return array<string, mixed>|null
 *
 * @see AP_Post::getStatusObject()
 */
function ap_get_post_status_object(string $status): ?array
{
    return AP_Post::getStatusObject($status);
}

/**
 * All registered post statuses.
 *
 * @return array<string, array<string, mixed>>
 *
 * @see AP_Post::getStatuses()
 */
function ap_get_post_statuses(): array
{
    return AP_Post::getStatuses();
}

/**
 * Whether a post status is registered.
 *
 * @see AP_Post::statusExists()
 */
function ap_post_status_exists(string $status): bool
{
    return AP_Post::statusExists($status);
}

/**
 * Register a post type (built-in or lightweight CPT).
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Post::registerType()
 */
function ap_register_post_type(string $type, array $args = []): void
{
    AP_Post::registerType($type, $args);
}

/**
 * Registered post type object, or null.
 *
 * @return array<string, mixed>|null
 *
 * @see AP_Post::getTypeObject()
 */
function ap_get_post_type_object(string $type): ?array
{
    return AP_Post::getTypeObject($type);
}

/**
 * All registered post types.
 *
 * @return array<string, array<string, mixed>>
 *
 * @see AP_Post::getTypes()
 */
function ap_get_post_types(): array
{
    return AP_Post::getTypes();
}

/**
 * Whether a post type is registered.
 *
 * @see AP_Post::typeExists()
 */
function ap_post_type_exists(string $type): bool
{
    return AP_Post::typeExists($type);
}

/**
 * Whether a post type is hierarchical (e.g. pages).
 *
 * @see AP_Post::typeIsHierarchical()
 */
function ap_is_post_type_hierarchical(string $type): bool
{
    return AP_Post::typeIsHierarchical($type);
}

/**
 * Whether a post type supports a feature.
 *
 * @see AP_Post::typeSupports()
 */
function ap_post_type_supports(string $type, string $feature): bool
{
    return AP_Post::typeSupports($type, $feature);
}

/**
 * Fetch a post by ID.
 *
 * @see AP_Post::get()
 */
function ap_get_post(int $id, ?AP_DB $db = null): ?AP_Post
{
    return AP_Post::get($id, $db);
}

/**
 * Fetch a post by slug and optional type.
 *
 * @see AP_Post::getBySlug()
 */
function ap_get_post_by_slug(string $slug, string $type = '', ?AP_DB $db = null): ?AP_Post
{
    return AP_Post::getBySlug($slug, $type, $db);
}

/**
 * Insert a post. Returns the new ID, or 0 on failure.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $args
 *
 * @see AP_Post::insert()
 */
function ap_insert_post(array $data, ?AP_DB $db = null, array $args = []): int
{
    return AP_Post::insert($data, $db, $args);
}

/**
 * Update a post by ID.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $args
 *
 * @see AP_Post::update()
 */
function ap_update_post(int $id, array $data, ?AP_DB $db = null, array $args = []): bool
{
    return AP_Post::update($id, $data, $db, $args);
}

/**
 * Soft-delete a post (status = trash).
 *
 * @see AP_Post::trash()
 */
function ap_trash_post(int $id, ?AP_DB $db = null): bool
{
    return AP_Post::trash($id, $db);
}

/**
 * Restore a trashed post.
 *
 * @see AP_Post::untrash()
 */
function ap_untrash_post(int $id, ?AP_DB $db = null): bool
{
    return AP_Post::untrash($id, $db);
}

/**
 * Delete a post (trash unless $force; permanent when force or already trash).
 *
 * @see AP_Post::delete()
 */
function ap_delete_post(int $id, bool $force = false, ?AP_DB $db = null): bool
{
    return AP_Post::delete($id, $force, $db);
}

/**
 * Whether a post type supports revisions.
 *
 * @see AP_Post::typeSupportsRevisions()
 */
function ap_post_type_supports_revisions(string $type): bool
{
    return AP_Post::typeSupportsRevisions($type);
}

/**
 * Whether the post (or ID) is a revision row.
 *
 * @see AP_Post::isRevision()
 */
function ap_is_revision(AP_Post|int $post, ?AP_DB $db = null): bool
{
    return AP_Post::isRevision($post, $db);
}

/**
 * Whether the post (or ID) is an autosave revision.
 *
 * @see AP_Post::isAutosave()
 */
function ap_is_autosave(AP_Post|int $post, ?AP_DB $db = null): bool
{
    return AP_Post::isAutosave($post, $db);
}

/**
 * Save a content revision of a post. Returns new revision ID or 0.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Post::saveRevision()
 */
function ap_save_post_revision(int $postId, ?AP_DB $db = null, array $args = []): int
{
    return AP_Post::saveRevision($postId, $db, $args);
}

/**
 * Create or update an autosave snapshot (does not change the parent post).
 *
 * @param array<string, mixed> $data post_title / post_content / post_excerpt
 *
 * @see AP_Post::autosave()
 */
function ap_autosave_post(int $postId, array $data, int $userId = 0, ?AP_DB $db = null): int
{
    return AP_Post::autosave($postId, $data, $userId, $db);
}

/**
 * Fetch autosave for a post (optional author filter).
 *
 * @see AP_Post::getAutosave()
 */
function ap_get_post_autosave(int $postId, int $userId = 0, ?AP_DB $db = null): ?AP_Post
{
    return AP_Post::getAutosave($postId, $userId, $db);
}

/**
 * List revisions for a parent post.
 *
 * @param array<string, mixed> $args
 *
 * @return list<AP_Post>
 *
 * @see AP_Post::getRevisions()
 */
function ap_get_post_revisions(int $postId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Post::getRevisions($postId, $args, $db);
}

/**
 * Count revisions for a parent post.
 *
 * @see AP_Post::countRevisions()
 */
function ap_count_post_revisions(
    int $postId,
    bool $includeAutosaves = false,
    ?AP_DB $db = null
): int {
    return AP_Post::countRevisions($postId, $includeAutosaves, $db);
}

/**
 * Restore a revision onto its parent post.
 *
 * @see AP_Post::restoreRevision()
 */
function ap_restore_post_revision(int $revisionId, ?AP_DB $db = null): bool
{
    return AP_Post::restoreRevision($revisionId, $db);
}

/**
 * Permanently delete a single revision.
 *
 * @see AP_Post::deleteRevision()
 */
function ap_delete_post_revision(int $revisionId, ?AP_DB $db = null): bool
{
    return AP_Post::deleteRevision($revisionId, $db);
}

/**
 * Simple post list via {@see AP_Post::query()} (lightweight filters).
 *
 * For full WP_Query-style vars (pagination, search, meta, loop flags), use
 * {@see ap_query()} / {@see AP_Query}.
 *
 * @param array<string, mixed> $args
 *
 * @return list<AP_Post>
 *
 * @see AP_Post::query()
 * @see ap_query()
 */
function ap_get_posts(array $args = [], ?AP_DB $db = null): array
{
    return AP_Post::query($args, $db);
}

/**
 * Run a content query and return the AP_Query instance.
 *
 * When $args is null, returns the global main query (creating an empty one
 * if needed). When $args is an array, builds a new secondary query.
 *
 * @param array<string, mixed>|null $args Query vars (post_type, s, paged, …).
 *
 * @see AP_Query
 */
function ap_query(?array $args = null, ?AP_DB $db = null): AP_Query
{
    if ($args === null) {
        if (!isset($GLOBALS['ap_query']) || !$GLOBALS['ap_query'] instanceof AP_Query) {
            $GLOBALS['ap_query'] = new AP_Query([], $db);
        }

        return $GLOBALS['ap_query'];
    }

    $query = new AP_Query($args, $db);
    // Secondary queries do not replace the main global unless none exists yet.
    if (!isset($GLOBALS['ap_query']) || !$GLOBALS['ap_query'] instanceof AP_Query) {
        $GLOBALS['ap_query'] = $query;
    }

    return $query;
}

/**
 * Set (or replace) the global main query.
 */
function ap_set_query(AP_Query $query): void
{
    $GLOBALS['ap_query'] = $query;
}

/**
 * Whether the main query has more posts in the loop.
 *
 * @see AP_Query::havePosts()
 */
function ap_have_posts(): bool
{
    return ap_query()->havePosts();
}

/**
 * Advance the main query loop and set the global $ap_post.
 *
 * @see AP_Query::thePost()
 */
function ap_the_post(): void
{
    $q = ap_query();
    $q->thePost();
    $GLOBALS['ap_post'] = $q->post;
}

/**
 * Rewind the main query loop.
 *
 * @see AP_Query::rewindPosts()
 */
function ap_rewind_posts(): void
{
    ap_query()->rewindPosts();
}

/**
 * Current post in the main loop (or null).
 */
function ap_get_queried_post(): ?AP_Post
{
    $q = ap_query();

    return $q->post;
}

// -----------------------------------------------------------------------------
// Themes / template hierarchy
// -----------------------------------------------------------------------------

/**
 * Active theme slug (stylesheet directory).
 *
 * @see AP_Theme::getStylesheet()
 */
function ap_get_stylesheet(?AP_DB $db = null): string
{
    return AP_Theme::getStylesheet($db);
}

/**
 * Parent theme slug (template directory).
 *
 * @see AP_Theme::getTemplate()
 */
function ap_get_template(?AP_DB $db = null): string
{
    return AP_Theme::getTemplate($db);
}

/**
 * Absolute path to the active theme directory.
 *
 * @see AP_Theme::getStylesheetDirectory()
 */
function ap_get_stylesheet_directory(?AP_DB $db = null): string
{
    return AP_Theme::getStylesheetDirectory($db);
}

/**
 * Absolute path to the parent theme directory.
 *
 * @see AP_Theme::getTemplateDirectory()
 */
function ap_get_template_directory(?AP_DB $db = null): string
{
    return AP_Theme::getTemplateDirectory($db);
}

/**
 * Public URI for the active theme directory.
 *
 * @see AP_Theme::getStylesheetUri()
 */
function ap_get_stylesheet_uri(?AP_DB $db = null): string
{
    return AP_Theme::getStylesheetUri($db);
}

/**
 * Public URI for the parent theme directory.
 *
 * @see AP_Theme::getTemplateUri()
 */
function ap_get_template_uri(?AP_DB $db = null): string
{
    return AP_Theme::getTemplateUri($db);
}

/**
 * Ordered template hierarchy candidates for the main (or given) query.
 *
 * @return list<string>
 *
 * @see AP_Theme::getHierarchy()
 */
function ap_get_template_hierarchy(?AP_Query $query = null, ?AP_DB $db = null): array
{
    return AP_Theme::getHierarchy($query, $db);
}

/**
 * Locate a template in the active theme stack (child then parent).
 *
 * @param list<string>|string $templates
 * @param array<string, mixed> $args
 *
 * @see AP_Theme::locateTemplate()
 */
function ap_locate_template(
    array|string $templates,
    bool $load = false,
    bool $requireOnce = true,
    array $args = [],
    ?AP_DB $db = null
): string {
    return AP_Theme::locateTemplate($templates, $load, $requireOnce, $args, $db);
}

/**
 * Load header.php (or header-{$name}.php) from the active theme.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Theme::getHeader()
 */
function ap_get_header(string $name = '', array $args = [], ?AP_DB $db = null): void
{
    AP_Theme::getHeader($name, $args, $db);
}

/**
 * Load footer.php (or footer-{$name}.php) from the active theme.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Theme::getFooter()
 */
function ap_get_footer(string $name = '', array $args = [], ?AP_DB $db = null): void
{
    AP_Theme::getFooter($name, $args, $db);
}

/**
 * Load sidebar.php (or sidebar-{$name}.php) from the active theme.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Theme::getSidebar()
 */
function ap_get_sidebar(string $name = '', array $args = [], ?AP_DB $db = null): void
{
    AP_Theme::getSidebar($name, $args, $db);
}

/**
 * Set the active theme (writes stylesheet + template options).
 *
 * @see AP_Theme::setActive()
 */
function ap_switch_theme(string $stylesheet, ?string $template = null, ?AP_DB $db = null): bool
{
    return AP_Theme::setActive($stylesheet, $template, $db);
}

/**
 * List installed themes (style.css headers keyed by slug).
 *
 * @return array<string, array<string, string>>
 *
 * @see AP_Theme::listThemes()
 */
function ap_get_themes(): array
{
    return AP_Theme::listThemes();
}

/**
 * Run the front-end template loader for the main query.
 *
 * @see AP_Theme::render()
 */
function ap_template_loader(?AP_Query $query = null, ?AP_DB $db = null): void
{
    AP_Theme::render($query, $db);
}

// -----------------------------------------------------------------------------
// Permalinks / rewrite
// -----------------------------------------------------------------------------

/**
 * Whether pretty permalinks are enabled.
 *
 * @see AP_Rewrite::usingPermalinks()
 */
function ap_using_permalinks(?AP_DB $db = null): bool
{
    return AP_Rewrite::usingPermalinks($db);
}

/**
 * Current permalink structure (empty string = plain query args).
 *
 * @see AP_Rewrite::getStructure()
 */
function ap_get_permalink_structure(?AP_DB $db = null): string
{
    return AP_Rewrite::getStructure($db);
}

/**
 * Set permalink structure and flush rewrite rules.
 *
 * @see AP_Rewrite::setStructure()
 */
function ap_set_permalink_structure(string $structure, ?AP_DB $db = null): bool
{
    return AP_Rewrite::setStructure($structure, $db);
}

/**
 * Regenerate and store rewrite rules.
 *
 * @return array<string, string>
 *
 * @see AP_Rewrite::flushRules()
 */
function ap_flush_rewrite_rules(?AP_DB $db = null): array
{
    return AP_Rewrite::flushRules($db);
}

/**
 * Parse a path (and optional GET vars) into query vars.
 *
 * When both $path and $get are null (default), uses REQUEST_URI + $_GET.
 * Pass an explicit path string (including '') to parse without superglobals.
 *
 * @param string|null          $path Path relative to home, or null for globals.
 * @param array<string, mixed>|null $get Query string vars, or null for $_GET when using globals.
 *
 * @return array<string, mixed>
 *
 * @see AP_Rewrite::parseRequest()
 * @see AP_Rewrite::parseFromGlobals()
 */
function ap_parse_request(?string $path = null, ?array $get = null, ?AP_DB $db = null): array
{
    if ($path === null && $get === null) {
        return AP_Rewrite::parseFromGlobals(null, null, $db);
    }

    return AP_Rewrite::parseRequest($path ?? '', $get ?? [], $db);
}

/**
 * Home URL (option `home`, then `siteurl`, then AP_SITEURL).
 *
 * @see AP_Rewrite::homeUrl()
 */
function ap_home_url(string $path = '', ?AP_DB $db = null): string
{
    return AP_Rewrite::homeUrl($path, $db);
}

/**
 * Site URL (core install URL).
 *
 * @see AP_Rewrite::siteUrl()
 */
function ap_site_url(string $path = '', ?AP_DB $db = null): string
{
    return AP_Rewrite::siteUrl($path, $db);
}

/**
 * Public permalink for a post or page.
 *
 * @see AP_Rewrite::getPermalink()
 */
function ap_get_permalink(AP_Post|int $post, ?AP_DB $db = null): string
{
    return AP_Rewrite::getPermalink($post, $db);
}

/**
 * Permalink for a hierarchical page.
 *
 * @see AP_Rewrite::getPageLink()
 */
function ap_get_page_link(AP_Post|int $page, ?AP_DB $db = null): string
{
    return AP_Rewrite::getPageLink($page, $db);
}

/**
 * Term archive link (category / tag / custom).
 *
 * @see AP_Rewrite::getTermLink()
 */
function ap_get_term_link(object|int $term, string $taxonomy = '', ?AP_DB $db = null): string
{
    return AP_Rewrite::getTermLink($term, $taxonomy, $db);
}

/**
 * Author archive link.
 *
 * @see AP_Rewrite::getAuthorLink()
 */
function ap_get_author_link(string $authorName, ?AP_DB $db = null): string
{
    return AP_Rewrite::getAuthorLink($authorName, $db);
}

/**
 * Search results link.
 *
 * @see AP_Rewrite::getSearchLink()
 */
function ap_get_search_link(string $query, ?AP_DB $db = null): string
{
    return AP_Rewrite::getSearchLink($query, $db);
}

/**
 * Feed URL (rss2 or atom).
 *
 * @see AP_Rewrite::getFeedLink()
 */
function ap_get_feed_link(string $feed = 'rss2', ?AP_DB $db = null): string
{
    if (class_exists('AP_Rewrite', false)) {
        return AP_Rewrite::getFeedLink($feed, $db);
    }

    $feed = strtolower(trim($feed)) !== '' ? strtolower(trim($feed)) : 'rss2';

    return '/?feed=' . rawurlencode($feed);
}

// -----------------------------------------------------------------------------
// Options API
// -----------------------------------------------------------------------------

/**
 * Read a site option.
 *
 * @param mixed $default
 *
 * @return mixed
 *
 * @see AP_Options::get()
 */
function ap_get_option(string $name, mixed $default = false, ?AP_DB $db = null): mixed
{
    return AP_Options::get($name, $default, $db);
}

/**
 * Insert or update a site option.
 *
 * @param mixed $value
 *
 * @see AP_Options::update()
 */
function ap_update_option(string $name, mixed $value, ?AP_DB $db = null): bool
{
    return AP_Options::update($name, $value, $db);
}

/**
 * Add a site option only when it does not already exist.
 *
 * @param mixed $value
 *
 * @see AP_Options::add()
 */
function ap_add_option(string $name, mixed $value, ?AP_DB $db = null): bool
{
    return AP_Options::add($name, $value, $db);
}

/**
 * Delete a site option.
 *
 * @see AP_Options::delete()
 */
function ap_delete_option(string $name, ?AP_DB $db = null): bool
{
    return AP_Options::delete($name, $db);
}

// -----------------------------------------------------------------------------
// Navigation menus
// -----------------------------------------------------------------------------

/**
 * Register a theme nav menu location.
 *
 * @see AP_Nav_Menu::registerLocation()
 */
function ap_register_nav_menu(string $location, string $description = ''): void
{
    if (!class_exists('AP_Nav_Menu', false)) {
        return;
    }
    AP_Nav_Menu::registerLocation($location, $description);
}

/**
 * Register multiple theme nav menu locations.
 *
 * @param array<string, string> $locations
 *
 * @see AP_Nav_Menu::registerLocations()
 */
function ap_register_nav_menus(array $locations): void
{
    if (!class_exists('AP_Nav_Menu', false)) {
        return;
    }
    AP_Nav_Menu::registerLocations($locations);
}

/**
 * Whether a theme location has an assigned menu with items.
 *
 * @see AP_Nav_Menu::hasNavMenu()
 */
function ap_has_nav_menu(string $location, ?AP_DB $db = null): bool
{
    if (!class_exists('AP_Nav_Menu', false)) {
        return false;
    }

    return AP_Nav_Menu::hasNavMenu($location, $db);
}

/**
 * Render (or return) a navigation menu.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Nav_Menu::render()
 */
function ap_nav_menu(array $args = [], ?AP_DB $db = null): string
{
    if (!class_exists('AP_Nav_Menu', false)) {
        return '';
    }

    return AP_Nav_Menu::render($args, $db);
}

/**
 * Save a navigation menu (name + items).
 *
 * @param list<array<string, mixed>> $items
 *
 * @see AP_Nav_Menu::saveMenu()
 */
function ap_save_nav_menu(string $slug, string $name, array $items = [], ?AP_DB $db = null): bool
{
    if (!class_exists('AP_Nav_Menu', false)) {
        return false;
    }

    return AP_Nav_Menu::saveMenu($slug, $name, $items, $db);
}

/**
 * Get a navigation menu by slug.
 *
 * @return array{name: string, items: list<array<string, mixed>>}|null
 *
 * @see AP_Nav_Menu::getMenu()
 */
function ap_get_nav_menu(string $slug, ?AP_DB $db = null): ?array
{
    return AP_Nav_Menu::getMenu($slug, $db);
}

/**
 * Assign menus to theme locations.
 *
 * @param array<string, string> $map
 *
 * @see AP_Nav_Menu::setLocationAssignments()
 */
function ap_set_nav_menu_locations(array $map, ?AP_DB $db = null): bool
{
    return AP_Nav_Menu::setLocationAssignments($map, $db);
}

/**
 * Child posts of a parent.
 *
 * @param array<string, mixed> $args
 *
 * @return list<AP_Post>
 *
 * @see AP_Post::getChildren()
 */
function ap_get_post_children(int $parentId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Post::getChildren($parentId, $args, $db);
}

/**
 * Ancestor IDs (parent → root).
 *
 * @return list<int>
 *
 * @see AP_Post::getAncestorIds()
 */
function ap_get_post_ancestor_ids(int $id, ?AP_DB $db = null): array
{
    return AP_Post::getAncestorIds($id, $db);
}

/**
 * Hierarchical page path of slugs (a/b/c).
 *
 * @see AP_Post::getPagePath()
 */
function ap_get_page_path(int $id, ?AP_DB $db = null): string
{
    return AP_Post::getPagePath($id, $db);
}

/**
 * Nested tree for hierarchical post types.
 *
 * @param array<string, mixed> $args
 *
 * @return list<array{post: AP_Post, children: list<array<string, mixed>>}>
 *
 * @see AP_Post::getTree()
 */
function ap_get_page_tree(array $args = [], ?AP_DB $db = null): array
{
    return AP_Post::getTree($args, $db);
}

/**
 * Sanitize a title into a URL slug.
 *
 * @see AP_Post::sanitizeSlug()
 */
function ap_sanitize_title(string $title): string
{
    return AP_Post::sanitizeSlug($title);
}

/**
 * Read post meta.
 *
 * @return ($single is true ? string|null : list<string>)
 *
 * @see AP_Post::getMeta()
 */
function ap_get_post_meta(
    int $postId,
    string $key,
    bool $single = true,
    ?AP_DB $db = null
): string|array|null {
    return AP_Post::getMeta($postId, $key, $single, $db);
}

/**
 * Update (or insert) post meta.
 *
 * @see AP_Post::updateMeta()
 */
function ap_update_post_meta(
    int $postId,
    string $key,
    string $value,
    ?AP_DB $db = null
): bool {
    return AP_Post::updateMeta($postId, $key, $value, $db);
}

/**
 * Delete post meta for a key.
 *
 * @see AP_Post::deleteMeta()
 */
function ap_delete_post_meta(int $postId, string $key, ?AP_DB $db = null): bool
{
    return AP_Post::deleteMeta($postId, $key, $db);
}

/**
 * Page template slug (default when unset).
 *
 * @see AP_Post::getPageTemplate()
 */
function ap_get_page_template(int $postId, ?AP_DB $db = null): string
{
    return AP_Post::getPageTemplate($postId, $db);
}

/**
 * Set page template slug.
 *
 * @see AP_Post::setPageTemplate()
 */
function ap_set_page_template(int $postId, string $template, ?AP_DB $db = null): bool
{
    return AP_Post::setPageTemplate($postId, $template, $db);
}

// -----------------------------------------------------------------------------
// Media library — uploads & attachments
// -----------------------------------------------------------------------------

/**
 * Handle a single $_FILES-style upload and create an attachment post.
 *
 * @param array<string, mixed> $file
 * @param array<string, mixed> $args
 *
 * @return array{ok: bool, id: int, file: string, url: string, type: string, error: string, post: ?AP_Post}
 *
 * @see AP_Media::handleUpload()
 */
function ap_handle_upload(array $file, array $args = [], ?AP_DB $db = null): array
{
    return AP_Media::handleUpload($file, $args, $db);
}

/**
 * Create an attachment for a file already under the uploads directory.
 *
 * @param array<string, mixed> $data
 *
 * @see AP_Media::insertAttachment()
 */
function ap_insert_attachment(array $data, ?AP_DB $db = null): int
{
    return AP_Media::insertAttachment($data, $db);
}

/**
 * Permanently delete an attachment post and its file.
 *
 * @see AP_Media::deleteAttachment()
 */
function ap_delete_attachment(int $id, ?AP_DB $db = null): bool
{
    return AP_Media::deleteAttachment($id, $db);
}

/**
 * Update attachment title / caption / description / alt text.
 *
 * @param array<string, mixed> $data
 *
 * @see AP_Media::updateAttachment()
 */
function ap_update_attachment(int $id, array $data, ?AP_DB $db = null): bool
{
    return AP_Media::updateAttachment($id, $data, $db);
}

/**
 * Absolute filesystem path for an attachment file.
 *
 * @see AP_Media::getAttachedFile()
 */
function ap_get_attached_file(int $id, ?AP_DB $db = null): string
{
    return AP_Media::getAttachedFile($id, $db);
}

/**
 * Public URL for an attachment file.
 *
 * @see AP_Media::getAttachmentUrl()
 */
function ap_get_attachment_url(int $id, ?AP_DB $db = null): string
{
    return AP_Media::getAttachmentUrl($id, $db);
}

/**
 * Image alt text for an attachment.
 *
 * @see AP_Media::getAltText()
 */
function ap_get_attachment_alt(int $id, ?AP_DB $db = null): string
{
    return AP_Media::getAltText($id, $db);
}

/**
 * Set image alt text for an attachment.
 *
 * @see AP_Media::setAltText()
 */
function ap_set_attachment_alt(int $id, string $alt, ?AP_DB $db = null): bool
{
    return AP_Media::setAltText($id, $alt, $db);
}

/**
 * Attachment metadata array (filesize, dimensions, …).
 *
 * @return array<string, mixed>
 *
 * @see AP_Media::getMetadata()
 */
function ap_get_attachment_metadata(int $id, ?AP_DB $db = null): array
{
    return AP_Media::getMetadata($id, $db);
}

/**
 * Upload directory paths and URLs for the current time (or $time).
 *
 * @return array{path: string, url: string, subdir: string, basedir: string, baseurl: string, error: string|false}
 *
 * @see AP_Media::uploadDir()
 */
function ap_upload_dir(?int $time = null): array
{
    return AP_Media::uploadDir($time);
}

/**
 * Query media library attachments.
 *
 * @param array<string, mixed> $args
 *
 * @return array{items: list<AP_Post>, total: int}
 *
 * @see AP_Media::query()
 */
function ap_get_media(array $args = [], ?AP_DB $db = null): array
{
    return AP_Media::query($args, $db);
}

/**
 * Whether a MIME type (or attachment post) is an image.
 *
 * @see AP_Media::isImage()
 */
function ap_attachment_is_image(AP_Post|string $postOrMime): bool
{
    return AP_Media::isImage($postOrMime);
}

/**
 * Validate a filename (and optional real path) against the upload allow-list.
 *
 * @return array{ok: bool, ext: string, type: string, error: string}
 *
 * @see AP_Media::checkFileType()
 */
function ap_check_filetype(string $filename, string $realPath = ''): array
{
    return AP_Media::checkFileType($filename, $realPath);
}

// -----------------------------------------------------------------------------
// Taxonomies (categories, tags, custom)
// -----------------------------------------------------------------------------

/**
 * Register a taxonomy.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Taxonomy::register()
 */
function ap_register_taxonomy(string $taxonomy, array $args = []): void
{
    AP_Taxonomy::register($taxonomy, $args);
}

/**
 * @return array<string, mixed>|null
 *
 * @see AP_Taxonomy::getObject()
 */
function ap_get_taxonomy(string $taxonomy): ?array
{
    return AP_Taxonomy::getObject($taxonomy);
}

/**
 * @return array<string, array<string, mixed>>
 *
 * @see AP_Taxonomy::getTaxonomies()
 */
function ap_get_taxonomies(): array
{
    return AP_Taxonomy::getTaxonomies();
}

/**
 * @see AP_Taxonomy::exists()
 */
function ap_taxonomy_exists(string $taxonomy): bool
{
    return AP_Taxonomy::exists($taxonomy);
}

/**
 * @see AP_Taxonomy::isHierarchical()
 */
function ap_is_taxonomy_hierarchical(string $taxonomy): bool
{
    return AP_Taxonomy::isHierarchical($taxonomy);
}

/**
 * Taxonomies for a post type.
 *
 * @return list<string>
 *
 * @see AP_Taxonomy::getObjectTaxonomies()
 */
function ap_get_object_taxonomies(string $postType): array
{
    return AP_Taxonomy::getObjectTaxonomies($postType);
}

/**
 * Insert a term.
 *
 * @param array<string, mixed> $args
 *
 * @return array{term_id: int, term_taxonomy_id: int}|int
 *
 * @see AP_Taxonomy::insertTerm()
 */
function ap_insert_term(
    string $name,
    string $taxonomy,
    array $args = [],
    ?AP_DB $db = null
): array|int {
    return AP_Taxonomy::insertTerm($name, $taxonomy, $args, $db);
}

/**
 * Update a term.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Taxonomy::updateTerm()
 */
function ap_update_term(
    int $termId,
    string $taxonomy,
    array $args,
    ?AP_DB $db = null
): bool {
    return AP_Taxonomy::updateTerm($termId, $taxonomy, $args, $db);
}

/**
 * Delete a term.
 *
 * @see AP_Taxonomy::deleteTerm()
 */
function ap_delete_term(int $termId, string $taxonomy, ?AP_DB $db = null): bool
{
    return AP_Taxonomy::deleteTerm($termId, $taxonomy, $db);
}

/**
 * Get a term by ID.
 *
 * @see AP_Taxonomy::getTerm()
 */
function ap_get_term(int $termId, string $taxonomy = '', ?AP_DB $db = null): ?object
{
    return AP_Taxonomy::getTerm($termId, $taxonomy, $db);
}

/**
 * Get a term by slug within a taxonomy.
 *
 * @see AP_Taxonomy::getTermBySlug()
 */
function ap_get_term_by_slug(string $slug, string $taxonomy, ?AP_DB $db = null): ?object
{
    return AP_Taxonomy::getTermBySlug($slug, $taxonomy, $db);
}

/**
 * List terms.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>|list<int>|list<string>|array<int, string>
 *
 * @see AP_Taxonomy::getTerms()
 */
function ap_get_terms(string $taxonomy, array $args = [], ?AP_DB $db = null): array
{
    return AP_Taxonomy::getTerms($taxonomy, $args, $db);
}

/**
 * Nested term tree (hierarchical taxonomies).
 *
 * @param array<string, mixed> $args
 *
 * @return list<array{term: object, children: list<array<string, mixed>>}>
 *
 * @see AP_Taxonomy::getTermTree()
 */
function ap_get_term_tree(
    string $taxonomy,
    array $args = [],
    int $parent = 0,
    ?AP_DB $db = null
): array {
    return AP_Taxonomy::getTermTree($taxonomy, $args, $parent, $db);
}

/**
 * Assign terms to a post/object.
 *
 * @param list<int|string>|int|string $terms
 *
 * @return list<int>
 *
 * @see AP_Taxonomy::setObjectTerms()
 */
function ap_set_object_terms(
    int $objectId,
    array|int|string $terms,
    string $taxonomy,
    bool $append = false,
    ?AP_DB $db = null
): array {
    return AP_Taxonomy::setObjectTerms($objectId, $terms, $taxonomy, $append, $db);
}

/**
 * Terms on a post/object.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>|list<int>|list<string>
 *
 * @see AP_Taxonomy::getObjectTerms()
 */
function ap_get_object_terms(
    int $objectId,
    string $taxonomy = '',
    array $args = [],
    ?AP_DB $db = null
): array {
    return AP_Taxonomy::getObjectTerms($objectId, $taxonomy, $args, $db);
}

/**
 * Remove terms from a post/object.
 *
 * @param list<int|string>|int|string $terms
 *
 * @see AP_Taxonomy::removeObjectTerms()
 */
function ap_remove_object_terms(
    int $objectId,
    array|int|string $terms,
    string $taxonomy,
    ?AP_DB $db = null
): bool {
    return AP_Taxonomy::removeObjectTerms($objectId, $terms, $taxonomy, $db);
}

/**
 * Object IDs that have given term IDs.
 *
 * @param list<int> $termIds
 * @param array<string, mixed> $args
 *
 * @return list<int>
 *
 * @see AP_Taxonomy::getObjectsInTerm()
 */
function ap_get_objects_in_term(array $termIds, array $args = [], ?AP_DB $db = null): array
{
    return AP_Taxonomy::getObjectsInTerm($termIds, $args, $db);
}

/**
 * Ensure default Uncategorized category exists; return its term_id.
 *
 * @see AP_Taxonomy::ensureDefaultCategory()
 */
function ap_ensure_default_category(?AP_DB $db = null): int
{
    return AP_Taxonomy::ensureDefaultCategory($db);
}

/**
 * Default category term_id.
 *
 * @see AP_Taxonomy::getDefaultCategoryId()
 */
function ap_get_default_category_id(?AP_DB $db = null): int
{
    return AP_Taxonomy::getDefaultCategoryId($db);
}

/**
 * Categories assigned to a post.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>|list<int>|list<string>
 */
function ap_get_post_categories(
    int $postId,
    array $args = [],
    ?AP_DB $db = null
): array {
    return AP_Taxonomy::getObjectTerms($postId, 'category', $args, $db);
}

/**
 * Tags assigned to a post.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>|list<int>|list<string>
 */
function ap_get_post_tags(int $postId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Taxonomy::getObjectTerms($postId, 'post_tag', $args, $db);
}

/**
 * Set categories on a post (replaces existing).
 *
 * @param list<int|string>|int|string $categories
 *
 * @return list<int>
 */
function ap_set_post_categories(
    int $postId,
    array|int|string $categories,
    ?AP_DB $db = null
): array {
    return AP_Taxonomy::setObjectTerms($postId, $categories, 'category', false, $db);
}

/**
 * Set tags on a post (replaces existing; string names create tags).
 *
 * @param list<int|string>|int|string $tags
 *
 * @return list<int>
 */
function ap_set_post_tags(
    int $postId,
    array|int|string $tags,
    ?AP_DB $db = null
): array {
    return AP_Taxonomy::setObjectTerms($postId, $tags, 'post_tag', false, $db);
}

// -----------------------------------------------------------------------------
// Comments — nested threads, moderation, spam hooks
// -----------------------------------------------------------------------------

/**
 * Fetch a comment by ID.
 *
 * @see AP_Comment::get()
 */
function ap_get_comment(int $id, ?AP_DB $db = null): ?AP_Comment
{
    return AP_Comment::get($id, $db);
}

/**
 * Insert a comment. Returns new ID or 0 on failure.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $args
 *
 * @see AP_Comment::insert()
 */
function ap_insert_comment(array $data, ?AP_DB $db = null, array $args = []): int
{
    return AP_Comment::insert($data, $db, $args);
}

/**
 * Update a comment.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $args
 *
 * @see AP_Comment::update()
 */
function ap_update_comment(int $id, array $data, ?AP_DB $db = null, array $args = []): bool
{
    return AP_Comment::update($id, $data, $db, $args);
}

/**
 * Soft-delete a comment (status = trash).
 *
 * @see AP_Comment::trash()
 */
function ap_trash_comment(int $id, ?AP_DB $db = null): bool
{
    return AP_Comment::trash($id, $db);
}

/**
 * Restore a trashed comment.
 *
 * @see AP_Comment::untrash()
 */
function ap_untrash_comment(int $id, ?AP_DB $db = null): bool
{
    return AP_Comment::untrash($id, $db);
}

/**
 * Delete a comment (trash unless $force; permanent when force or already trash).
 *
 * @see AP_Comment::delete()
 */
function ap_delete_comment(int $id, bool $force = false, ?AP_DB $db = null): bool
{
    return AP_Comment::delete($id, $force, $db);
}

/**
 * Approve a comment.
 *
 * @see AP_Comment::approve()
 */
function ap_approve_comment(int $id, ?AP_DB $db = null): bool
{
    return AP_Comment::approve($id, $db);
}

/**
 * Unapprove a comment (hold for moderation).
 *
 * @see AP_Comment::unapprove()
 */
function ap_unapprove_comment(int $id, ?AP_DB $db = null): bool
{
    return AP_Comment::unapprove($id, $db);
}

/**
 * Mark a comment as spam.
 *
 * @see AP_Comment::spam()
 */
function ap_spam_comment(int $id, ?AP_DB $db = null): bool
{
    return AP_Comment::spam($id, $db);
}

/**
 * Remove spam flag (back to pending).
 *
 * @see AP_Comment::unspam()
 */
function ap_unspam_comment(int $id, ?AP_DB $db = null): bool
{
    return AP_Comment::unspam($id, $db);
}

/**
 * Set comment moderation status.
 *
 * @see AP_Comment::setStatus()
 */
function ap_set_comment_status(int $id, string $status, ?AP_DB $db = null): bool
{
    return AP_Comment::setStatus($id, $status, $db);
}

/**
 * List comments with filters.
 *
 * @param array<string, mixed> $args
 *
 * @return list<AP_Comment>
 *
 * @see AP_Comment::query()
 */
function ap_get_comments(array $args = [], ?AP_DB $db = null): array
{
    return AP_Comment::query($args, $db);
}

/**
 * Count comments matching filters.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Comment::count()
 */
function ap_count_comments(array $args = [], ?AP_DB $db = null): int
{
    return AP_Comment::count($args, $db);
}

/**
 * Approved comments for a post (flat list).
 *
 * @param array<string, mixed> $args
 *
 * @return list<AP_Comment>
 *
 * @see AP_Comment::getByPost()
 */
function ap_get_post_comments(int $postId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Comment::getByPost($postId, $args, $db);
}

/**
 * Nested comment tree for a post.
 *
 * @param array<string, mixed> $args
 *
 * @return list<array{comment: AP_Comment, children: list}>
 *
 * @see AP_Comment::getTree()
 */
function ap_get_comment_tree(int $postId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Comment::getTree($postId, $args, $db);
}

/**
 * Recount approved comments on a post.
 *
 * @see AP_Comment::updateCommentCount()
 */
function ap_update_comment_count(int $postId, ?AP_DB $db = null): int
{
    return AP_Comment::updateCommentCount($postId, $db);
}

/**
 * Register a pluggable spam checker.
 *
 * @param callable(array<string, mixed>): (bool|string) $callback
 *
 * @see AP_Comment::registerSpamChecker()
 */
function ap_register_comment_spam_checker(callable $callback): void
{
    AP_Comment::registerSpamChecker($callback);
}

/**
 * @return string|list<string>|null
 *
 * @see AP_Comment::getMeta()
 */
function ap_get_comment_meta(
    int $commentId,
    string $key,
    bool $single = true,
    ?AP_DB $db = null
): string|array|null {
    return AP_Comment::getMeta($commentId, $key, $single, $db);
}

/**
 * @see AP_Comment::updateMeta()
 */
function ap_update_comment_meta(
    int $commentId,
    string $key,
    string $value,
    ?AP_DB $db = null
): bool {
    return AP_Comment::updateMeta($commentId, $key, $value, $db);
}

/**
 * @see AP_Comment::deleteMeta()
 */
function ap_delete_comment_meta(int $commentId, string $key, ?AP_DB $db = null): bool
{
    return AP_Comment::deleteMeta($commentId, $key, $db);
}

// -----------------------------------------------------------------------------
// Escaping & sanitization (output / input helpers)
// -----------------------------------------------------------------------------

/**
 * Escape for HTML body text.
 */
function ap_esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Escape for HTML attribute values.
 */
function ap_esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Escape for use in a URL (query args / path segments after encoding).
 */
function ap_esc_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    // Allow relative admin paths and absolute http(s).
    if (preg_match('#^(?:https?:)?//#i', $url) === 1) {
        $filtered = filter_var($url, FILTER_SANITIZE_URL);
        if (!is_string($filtered) || $filtered === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $filtered) !== 1) {
            return '';
        }

        return htmlspecialchars($filtered, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // Relative path: strip control chars / quotes.
    $url = str_replace(["\r", "\n", "\0", '"', "'", '<', '>'], '', $url);

    return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Escape for HTML textarea content (same as esc_html; named for intent).
 */
function ap_esc_textarea(string $text): string
{
    return ap_esc_html($text);
}

/**
 * Sanitize a single-line text field (strip tags, normalize whitespace).
 */
function ap_sanitize_text_field(string $value): string
{
    $value = ap_strip_all_tags($value);
    $value = preg_replace('/[\r\n\t]+/', ' ', $value) ?? $value;
    $value = preg_replace('/[ ]{2,}/', ' ', $value) ?? $value;
    $value = trim($value);

    return $value;
}

/**
 * Sanitize multiline text (strip tags, keep newlines).
 */
function ap_sanitize_textarea_field(string $value): string
{
    $value = ap_strip_all_tags($value);
    $value = str_replace("\0", '', $value);

    return trim($value);
}

/**
 * Strip HTML tags (and script/style blocks) for sanitization.
 */
function ap_strip_all_tags(string $value): string
{
    $value = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $value) ?? $value;
    $value = strip_tags($value);

    return $value;
}

// -----------------------------------------------------------------------------
// Nonces (CSRF)
// -----------------------------------------------------------------------------

/**
 * Create a nonce for an action.
 *
 * @see AP_Nonce::create()
 */
function ap_create_nonce(string $action = '-1', ?int $userId = null): string
{
    return AP_Nonce::create($action, $userId);
}

/**
 * Verify a nonce. Returns 1 / 2 on success (tick age), false on failure.
 *
 * @return int|false
 *
 * @see AP_Nonce::verify()
 */
function ap_verify_nonce(string $nonce, string $action = '-1', ?int $userId = null): int|false
{
    return AP_Nonce::verify($nonce, $action, $userId);
}

/**
 * Whether a nonce is valid.
 *
 * @see AP_Nonce::check()
 */
function ap_check_nonce(string $nonce, string $action = '-1', ?int $userId = null): bool
{
    return AP_Nonce::check($nonce, $action, $userId);
}

/**
 * HTML hidden fields for a nonce (and optional referer).
 *
 * @see AP_Nonce::field()
 */
function ap_nonce_field(
    string $action = '-1',
    string $name = '_ap_nonce',
    bool $referer = true,
    ?int $userId = null
): string {
    return AP_Nonce::field($action, $name, $referer, $userId);
}
