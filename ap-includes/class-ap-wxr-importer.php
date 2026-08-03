<?php

/**
 * WordPress WXR (WordPress eXtended RSS) importer.
 *
 * Imports authors, categories, tags, posts/pages (and registered custom types),
 * post meta, hierarchical parents, and nested comments from a classic WP export
 * (.xml WXR 1.0–1.2). Attachment rows are created with original URLs in meta;
 * remote media download is out of scope for this increment.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Parse and import WordPress WXR export files into AgoraPress tables.
 */
class AP_Wxr_Importer
{
    /** Default max WXR file size (32 MiB). */
    public const DEFAULT_MAX_BYTES = 33554432;

    /** Postmeta key storing the original WordPress post ID. */
    public const META_WXR_POST_ID = '_ap_wxr_post_id';

    /** Postmeta key storing the original attachment URL (when no local file). */
    public const META_ATTACHMENT_URL = '_ap_wxr_attachment_url';

    /** Usermeta key storing the original WordPress author ID. */
    public const META_WXR_AUTHOR_ID = '_ap_wxr_author_id';

    /**
     * Post types skipped even when present in the export.
     *
     * @var list<string>
     */
    public const SKIP_POST_TYPES = [
        'nav_menu_item',
        'revision',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
        'wp_font_family',
        'wp_font_face',
    ];

    /** WordPress export namespaces used for lookup. */
    private const NS_WP = 'http://wordpress.org/export/';

    private const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    private const NS_DC = 'http://purl.org/dc/elements/1.1/';

    private const NS_EXCERPT = 'http://wordpress.org/export/1.2/excerpt/';

    /**
     * Import from a filesystem path to a WXR XML file.
     *
     * @param array{
     *   max_bytes?: int,
     *   import_authors?: bool,
     *   import_attachments?: bool,
     *   import_comments?: bool,
     *   default_author?: int,
     *   skip_post_types?: list<string>
     * } $args
     *
     * @return array{
     *   ok: bool,
     *   authors: int,
     *   authors_created: int,
     *   authors_mapped: int,
     *   categories: int,
     *   tags: int,
     *   terms: int,
     *   posts: int,
     *   pages: int,
     *   attachments: int,
     *   other_posts: int,
     *   comments: int,
     *   skipped: int,
     *   wxr_version: string,
     *   base_url: string,
     *   site_title: string,
     *   errors: list<string>,
     *   warnings: list<string>,
     *   author_map: array<string, int>,
     *   post_map: array<int, int>
     * }
     */
    public static function importFromFile(string $path, ?AP_DB $db = null, array $args = []): array
    {
        $result = self::emptyResult();

        $path = trim($path);
        if ($path === '' || !is_readable($path)) {
            $result['errors'][] = 'WXR file is missing or not readable.';

            return $result;
        }

        $size = @filesize($path);
        if (!is_int($size) || $size < 1) {
            $result['errors'][] = 'WXR file is empty.';

            return $result;
        }

        $max = isset($args['max_bytes']) ? (int) $args['max_bytes'] : self::maxBytes();
        if ($max < 1) {
            $max = self::DEFAULT_MAX_BYTES;
        }
        if ($size > $max) {
            $result['errors'][] = 'WXR file exceeds the maximum size of ' . self::formatBytes($max) . '.';

            return $result;
        }

        $xml = @file_get_contents($path);
        if ($xml === false || $xml === '') {
            $result['errors'][] = 'Could not read the WXR file.';

            return $result;
        }

        return self::importFromString($xml, $db, $args);
    }

    /**
     * Import from a WXR XML string.
     *
     * @param array<string, mixed> $args Same as {@see importFromFile()}.
     *
     * @return array<string, mixed>
     */
    public static function importFromString(string $xml, ?AP_DB $db = null, array $args = []): array
    {
        $result = self::emptyResult();
        $db = self::resolveDb($db);

        $parsed = self::parse($xml);
        if ($parsed['errors'] !== []) {
            $result['errors'] = $parsed['errors'];

            return $result;
        }

        $result['wxr_version'] = $parsed['wxr_version'];
        $result['base_url'] = $parsed['base_url'];
        $result['site_title'] = $parsed['site_title'];
        if ($parsed['warnings'] !== []) {
            $result['warnings'] = array_merge($result['warnings'], $parsed['warnings']);
        }

        $importAuthors = !array_key_exists('import_authors', $args) || !empty($args['import_authors']);
        $importAttachments = !array_key_exists('import_attachments', $args) || !empty($args['import_attachments']);
        $importComments = !array_key_exists('import_comments', $args) || !empty($args['import_comments']);
        $defaultAuthor = max(0, (int) ($args['default_author'] ?? 0));

        $skipTypes = self::SKIP_POST_TYPES;
        if (!empty($args['skip_post_types']) && is_array($args['skip_post_types'])) {
            foreach ($args['skip_post_types'] as $t) {
                $t = self::sanitizeKey((string) $t);
                if ($t !== '') {
                    $skipTypes[] = $t;
                }
            }
        }
        $skipTypes = array_values(array_unique($skipTypes));

        // Ensure registries are ready.
        if (class_exists('AP_Post', false)) {
            AP_Post::ensureBuiltins();
        }
        if (class_exists('AP_Taxonomy', false)) {
            AP_Taxonomy::ensureBuiltins();
        }
        if (class_exists('AP_Roles', false)) {
            AP_Roles::ensureDefaults($db);
        }

        // 1) Authors
        /** @var array<string, int> $authorMap login => ap user id */
        $authorMap = [];
        /** @var array<int, int> $authorIdMap wp author id => ap user id */
        $authorIdMap = [];

        if ($importAuthors) {
            foreach ($parsed['authors'] as $author) {
                $map = self::importAuthor($author, $db, $result);
                if ($map['user_id'] > 0) {
                    $login = $map['login'];
                    if ($login !== '') {
                        $authorMap[$login] = $map['user_id'];
                    }
                    if ($map['wp_id'] > 0) {
                        $authorIdMap[$map['wp_id']] = $map['user_id'];
                    }
                }
            }
        }
        $result['author_map'] = $authorMap;

        // 2) Categories (two-pass for parents)
        /** @var array<string, int> $catSlugMap nicename => term_id */
        $catSlugMap = [];
        foreach ($parsed['categories'] as $cat) {
            $slug = $cat['slug'] !== '' ? $cat['slug'] : self::sanitizeSlug($cat['name']);
            if ($slug === '') {
                $result['warnings'][] = 'Skipped category with empty slug/name.';
                continue;
            }
            $term = self::ensureTerm($cat['name'] !== '' ? $cat['name'] : $slug, 'category', [
                'slug' => $slug,
                'description' => $cat['description'],
                'parent' => 0,
            ], $db);
            if ($term > 0) {
                $catSlugMap[$slug] = $term;
                $result['categories']++;
            }
        }
        // Apply category parents.
        foreach ($parsed['categories'] as $cat) {
            $slug = $cat['slug'] !== '' ? $cat['slug'] : self::sanitizeSlug($cat['name']);
            $parentSlug = $cat['parent'];
            if ($slug === '' || $parentSlug === '' || !isset($catSlugMap[$slug])) {
                continue;
            }
            $parentId = $catSlugMap[$parentSlug] ?? 0;
            if ($parentId < 1) {
                continue;
            }
            AP_Taxonomy::updateTerm($catSlugMap[$slug], 'category', ['parent' => $parentId], $db);
        }

        // 3) Tags
        /** @var array<string, int> $tagSlugMap */
        $tagSlugMap = [];
        foreach ($parsed['tags'] as $tag) {
            $slug = $tag['slug'] !== '' ? $tag['slug'] : self::sanitizeSlug($tag['name']);
            if ($slug === '') {
                continue;
            }
            $term = self::ensureTerm($tag['name'] !== '' ? $tag['name'] : $slug, 'post_tag', [
                'slug' => $slug,
                'description' => $tag['description'],
            ], $db);
            if ($term > 0) {
                $tagSlugMap[$slug] = $term;
                $result['tags']++;
            }
        }

        // 4) Custom terms (only taxonomies already registered)
        foreach ($parsed['terms'] as $termRow) {
            $taxonomy = self::sanitizeKey($termRow['taxonomy']);
            if ($taxonomy === '' || !AP_Taxonomy::exists($taxonomy)) {
                $result['warnings'][] = 'Skipped term for unknown taxonomy: ' . $taxonomy;
                $result['skipped']++;
                continue;
            }
            if (in_array($taxonomy, ['category', 'post_tag', 'nav_menu'], true)) {
                // Categories/tags handled above; nav menus out of scope.
                continue;
            }
            $slug = $termRow['slug'] !== '' ? $termRow['slug'] : self::sanitizeSlug($termRow['name']);
            if ($slug === '') {
                continue;
            }
            $termId = self::ensureTerm($termRow['name'] !== '' ? $termRow['name'] : $slug, $taxonomy, [
                'slug' => $slug,
                'description' => $termRow['description'],
            ], $db);
            if ($termId > 0) {
                $result['terms']++;
            }
        }

        // 5) Posts — first pass (create without parents)
        /** @var array<int, int> $postMap wp post id => ap post id */
        $postMap = [];
        /** @var list<array{wp_id: int, parent_wp: int}> $pendingParents */
        $pendingParents = [];
        /** @var list<array{wp_id: int, comments: list<array<string, mixed>>}> $pendingComments */
        $pendingComments = [];

        foreach ($parsed['items'] as $item) {
            $type = self::sanitizeKey($item['post_type']);
            if ($type === '') {
                $type = 'post';
            }

            if (in_array($type, $skipTypes, true)) {
                $result['skipped']++;
                continue;
            }
            if ($type === 'attachment' && !$importAttachments) {
                $result['skipped']++;
                continue;
            }

            // Unknown custom types: import as 'post' with a warning unless registered.
            $insertType = $type;
            if (class_exists('AP_Post', false) && !AP_Post::typeExists($type)) {
                if ($type === 'attachment') {
                    // attachment is built-in.
                } else {
                    $result['warnings'][] = 'Unknown post type "' . $type . '" — imported as post.';
                    $insertType = 'post';
                }
            }

            $status = self::mapPostStatus($item['status']);
            if ($status === '') {
                $status = 'draft';
            }

            $authorId = $defaultAuthor;
            $creator = $item['creator'];
            if ($creator !== '' && isset($authorMap[$creator])) {
                $authorId = $authorMap[$creator];
            } elseif ($creator !== '' && class_exists('AP_User', false)) {
                $existing = AP_User::getByLogin($creator, $db);
                if ($existing !== null) {
                    $authorId = (int) $existing->ID;
                    $authorMap[$creator] = $authorId;
                }
            }
            if ($authorId < 1 && $defaultAuthor > 0) {
                $authorId = $defaultAuthor;
            }

            $data = [
                'post_author' => $authorId,
                'post_date' => self::normalizeDatetime($item['post_date']),
                'post_date_gmt' => self::normalizeDatetime($item['post_date_gmt']),
                'post_content' => $item['content'],
                'post_title' => $item['title'],
                'post_excerpt' => $item['excerpt'],
                'post_status' => $status,
                'comment_status' => $item['comment_status'] !== '' ? $item['comment_status'] : 'open',
                'ping_status' => $item['ping_status'] !== '' ? $item['ping_status'] : 'open',
                'post_password' => $item['post_password'],
                'post_name' => $item['post_name'],
                'post_modified' => self::normalizeDatetime($item['post_modified'] !== '' ? $item['post_modified'] : $item['post_date']),
                'post_modified_gmt' => self::normalizeDatetime(
                    $item['post_modified_gmt'] !== '' ? $item['post_modified_gmt'] : $item['post_date_gmt']
                ),
                'post_parent' => 0,
                'guid' => $item['guid'],
                'menu_order' => $item['menu_order'],
                'post_type' => $insertType,
                'post_mime_type' => $item['post_mime_type'],
            ];

            if (!empty($item['is_sticky'])) {
                $data['sticky'] = true;
            }

            $newId = AP_Post::insert($data, $db, ['strict' => false]);
            if ($newId < 1) {
                $result['warnings'][] = 'Failed to import item: ' . ($item['title'] !== '' ? $item['title'] : '(untitled)');
                $result['skipped']++;
                continue;
            }

            $wpId = $item['post_id'];
            if ($wpId > 0) {
                $postMap[$wpId] = $newId;
                AP_Post::updateMeta($newId, self::META_WXR_POST_ID, (string) $wpId, $db);
            }

            // Post meta (skip empty keys; skip serialized PHP objects for safety later if needed).
            foreach ($item['postmeta'] as $meta) {
                $key = $meta['key'];
                if ($key === '' || $key === self::META_WXR_POST_ID) {
                    continue;
                }
                // Skip auto-draft/edit locks from WP.
                if (str_starts_with($key, '_edit_lock') || str_starts_with($key, '_edit_last')) {
                    continue;
                }
                AP_Post::updateMeta($newId, $key, $meta['value'], $db);
            }

            if ($type === 'attachment' && $item['attachment_url'] !== '') {
                AP_Post::updateMeta($newId, self::META_ATTACHMENT_URL, $item['attachment_url'], $db);
            }

            // Categories / tags / custom tax from <category domain="...">
            $catIds = [];
            $tagIds = [];
            /** @var array<string, list<int|string>> $customTax */
            $customTax = [];
            foreach ($item['categories'] as $c) {
                $domain = self::sanitizeKey($c['domain']);
                $nicename = $c['nicename'];
                $name = $c['name'];
                if ($domain === 'category' || $domain === '') {
                    $slug = $nicename !== '' ? $nicename : self::sanitizeSlug($name);
                    if ($slug !== '' && isset($catSlugMap[$slug])) {
                        $catIds[] = $catSlugMap[$slug];
                    } elseif ($name !== '' || $slug !== '') {
                        $tid = self::ensureTerm($name !== '' ? $name : $slug, 'category', [
                            'slug' => $slug !== '' ? $slug : self::sanitizeSlug($name),
                        ], $db);
                        if ($tid > 0) {
                            $catIds[] = $tid;
                            if ($slug !== '') {
                                $catSlugMap[$slug] = $tid;
                            }
                        }
                    }
                } elseif ($domain === 'post_tag' || $domain === 'tag') {
                    $slug = $nicename !== '' ? $nicename : self::sanitizeSlug($name);
                    if ($slug !== '' && isset($tagSlugMap[$slug])) {
                        $tagIds[] = $tagSlugMap[$slug];
                    } elseif ($name !== '' || $slug !== '') {
                        $tid = self::ensureTerm($name !== '' ? $name : $slug, 'post_tag', [
                            'slug' => $slug !== '' ? $slug : self::sanitizeSlug($name),
                        ], $db);
                        if ($tid > 0) {
                            $tagIds[] = $tid;
                            if ($slug !== '') {
                                $tagSlugMap[$slug] = $tid;
                            }
                        }
                    }
                } elseif (AP_Taxonomy::exists($domain)) {
                    $slug = $nicename !== '' ? $nicename : self::sanitizeSlug($name);
                    $tid = self::ensureTerm($name !== '' ? $name : $slug, $domain, [
                        'slug' => $slug !== '' ? $slug : self::sanitizeSlug($name),
                    ], $db);
                    if ($tid > 0) {
                        $customTax[$domain][] = $tid;
                    }
                }
            }

            if ($insertType === 'post' || $insertType === 'page') {
                if ($catIds !== [] && ($insertType === 'post' || AP_Taxonomy::exists('category'))) {
                    // Pages usually don't use categories; only set for posts.
                    if ($insertType === 'post') {
                        AP_Taxonomy::setObjectTerms($newId, array_values(array_unique($catIds)), 'category', false, $db);
                    }
                }
                if ($tagIds !== [] && $insertType === 'post') {
                    AP_Taxonomy::setObjectTerms($newId, array_values(array_unique($tagIds)), 'post_tag', false, $db);
                }
            } elseif ($catIds !== [] && AP_Taxonomy::exists('category')) {
                // Custom types may use categories if object_type allows — set when taxonomy exists.
                AP_Taxonomy::setObjectTerms($newId, array_values(array_unique($catIds)), 'category', false, $db);
            }
            foreach ($customTax as $tax => $ids) {
                AP_Taxonomy::setObjectTerms($newId, array_values(array_unique($ids)), $tax, false, $db);
            }

            if ($item['post_parent'] > 0 && $wpId > 0) {
                $pendingParents[] = ['wp_id' => $wpId, 'parent_wp' => $item['post_parent']];
            }

            if ($importComments && $item['comments'] !== []) {
                $pendingComments[] = ['wp_id' => $wpId, 'ap_id' => $newId, 'comments' => $item['comments']];
            }

            match ($type) {
                'page' => $result['pages']++,
                'attachment' => $result['attachments']++,
                'post' => $result['posts']++,
                default => $result['other_posts']++,
            };
        }

        // 6) Remap parents
        foreach ($pendingParents as $pp) {
            $childAp = $postMap[$pp['wp_id']] ?? 0;
            $parentAp = $postMap[$pp['parent_wp']] ?? 0;
            if ($childAp < 1 || $parentAp < 1) {
                continue;
            }
            AP_Post::update($childAp, ['post_parent' => $parentAp], $db, [
                'strict' => false,
                'create_revision' => false,
            ]);
        }

        // 7) Comments (remap nested parents)
        if ($importComments) {
            foreach ($pendingComments as $bundle) {
                $apPostId = (int) $bundle['ap_id'];
                if ($apPostId < 1) {
                    continue;
                }
                /** @var array<int, int> $commentMap wp comment id => ap comment id */
                $commentMap = [];
                // Sort so parents tend to come first (wp:comment_id order is not guaranteed).
                $comments = $bundle['comments'];
                usort($comments, static function (array $a, array $b): int {
                    return ($a['comment_id'] ?? 0) <=> ($b['comment_id'] ?? 0);
                });

                // Multiple passes for parent remapping.
                $remaining = $comments;
                $guard = 0;
                while ($remaining !== [] && $guard < 50) {
                    $guard++;
                    $next = [];
                    foreach ($remaining as $c) {
                        $wpParent = (int) ($c['comment_parent'] ?? 0);
                        $apParent = 0;
                        if ($wpParent > 0) {
                            if (!isset($commentMap[$wpParent])) {
                                $next[] = $c;
                                continue;
                            }
                            $apParent = $commentMap[$wpParent];
                        }

                        $userId = 0;
                        $cLogin = (string) ($c['comment_user_login'] ?? '');
                        if ($cLogin !== '' && isset($authorMap[$cLogin])) {
                            $userId = $authorMap[$cLogin];
                        } elseif (!empty($c['user_id']) && isset($authorIdMap[(int) $c['user_id']])) {
                            $userId = $authorIdMap[(int) $c['user_id']];
                        }

                        $approved = self::mapCommentApproved((string) ($c['comment_approved'] ?? '1'));

                        $cid = AP_Comment::insert([
                            'comment_post_ID' => $apPostId,
                            'comment_author' => (string) ($c['comment_author'] ?? ''),
                            'comment_author_email' => (string) ($c['comment_author_email'] ?? ''),
                            'comment_author_url' => (string) ($c['comment_author_url'] ?? ''),
                            'comment_author_IP' => (string) ($c['comment_author_IP'] ?? ''),
                            'comment_date' => self::normalizeDatetime((string) ($c['comment_date'] ?? '')),
                            'comment_date_gmt' => self::normalizeDatetime((string) ($c['comment_date_gmt'] ?? '')),
                            'comment_content' => (string) ($c['comment_content'] ?? ''),
                            'comment_approved' => $approved,
                            'comment_agent' => (string) ($c['comment_agent'] ?? ''),
                            'comment_type' => (string) ($c['comment_type'] ?? 'comment'),
                            'comment_parent' => $apParent,
                            'user_id' => $userId,
                        ], $db, [
                            'check_open' => false,
                            'run_spam' => false,
                            'update_count' => true,
                        ]);

                        if ($cid > 0) {
                            $wpCid = (int) ($c['comment_id'] ?? 0);
                            if ($wpCid > 0) {
                                $commentMap[$wpCid] = $cid;
                            }
                            $result['comments']++;
                        } else {
                            $result['warnings'][] = 'Failed to import a comment on post #' . $apPostId . '.';
                        }
                    }
                    if (count($next) === count($remaining)) {
                        // Orphan parents — import with parent 0.
                        foreach ($next as $c) {
                            $c['comment_parent'] = 0;
                            $cid = AP_Comment::insert([
                                'comment_post_ID' => $apPostId,
                                'comment_author' => (string) ($c['comment_author'] ?? ''),
                                'comment_author_email' => (string) ($c['comment_author_email'] ?? ''),
                                'comment_author_url' => (string) ($c['comment_author_url'] ?? ''),
                                'comment_author_IP' => (string) ($c['comment_author_IP'] ?? ''),
                                'comment_date' => self::normalizeDatetime((string) ($c['comment_date'] ?? '')),
                                'comment_date_gmt' => self::normalizeDatetime((string) ($c['comment_date_gmt'] ?? '')),
                                'comment_content' => (string) ($c['comment_content'] ?? ''),
                                'comment_approved' => self::mapCommentApproved((string) ($c['comment_approved'] ?? '1')),
                                'comment_agent' => (string) ($c['comment_agent'] ?? ''),
                                'comment_type' => (string) ($c['comment_type'] ?? 'comment'),
                                'comment_parent' => 0,
                                'user_id' => 0,
                            ], $db, [
                                'check_open' => false,
                                'run_spam' => false,
                                'update_count' => true,
                            ]);
                            if ($cid > 0) {
                                $result['comments']++;
                            }
                        }
                        break;
                    }
                    $remaining = $next;
                }
            }
        }

        $result['post_map'] = $postMap;
        $result['ok'] = $result['errors'] === [];

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_wxr_imported', $result, $db);
        }

        return $result;
    }

    /**
     * Handle an uploaded WXR file from a multipart form field.
     *
     * @param array<string, mixed> $file Typically $_FILES['wxr']
     * @param array<string, mixed> $args Passed to importFromFile
     *
     * @return array<string, mixed>
     */
    public static function handleUpload(array $file, ?AP_DB $db = null, array $args = []): array
    {
        $result = self::emptyResult();

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $result['errors'][] = self::uploadErrorMessage($error);

            return $result;
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            // Allow non-upload tmp paths in tests when AP_WXR_ALLOW_LOCAL is set.
            if (!(defined('AP_WXR_ALLOW_LOCAL') && AP_WXR_ALLOW_LOCAL) || !is_readable($tmp)) {
                $result['errors'][] = 'Invalid upload.';

                return $result;
            }
        }

        $name = (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== '' && !in_array($ext, ['xml', 'wxr'], true)) {
            $result['errors'][] = 'Please upload a WordPress export file (.xml).';

            return $result;
        }

        // Quick content sniff: must look like XML / RSS / WXR.
        $head = (string) @file_get_contents($tmp, false, null, 0, 2048);
        if ($head === '' || (!str_contains($head, '<?xml') && !str_contains($head, '<rss') && !str_contains($head, 'wordpress.org/export'))) {
            // Allow files that start with BOM or whitespace then xml.
            $trim = ltrim($head, "\xEF\xBB\xBF \t\r\n");
            if ($trim === '' || ($trim[0] !== '<' && !str_contains($trim, 'wordpress'))) {
                $result['errors'][] = 'File does not look like a WordPress WXR export.';

                return $result;
            }
        }

        return self::importFromFile($tmp, $db, $args);
    }

    /**
     * Detect whether a string looks like a WXR export (lightweight check).
     */
    public static function isWxr(string $xml): bool
    {
        $sample = substr(ltrim($xml, "\xEF\xBB\xBF \t\r\n"), 0, 4000);
        if ($sample === '') {
            return false;
        }
        if (stripos($sample, 'wordpress.org/export') !== false) {
            return true;
        }
        if (stripos($sample, '<wp:wxr_version') !== false || stripos($sample, 'wxr_version') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Parse WXR XML into structured arrays (no DB writes).
     *
     * @return array{
     *   errors: list<string>,
     *   warnings: list<string>,
     *   wxr_version: string,
     *   base_url: string,
     *   site_title: string,
     *   authors: list<array<string, mixed>>,
     *   categories: list<array<string, string>>,
     *   tags: list<array<string, string>>,
     *   terms: list<array<string, string>>,
     *   items: list<array<string, mixed>>
     * }
     */
    public static function parse(string $xml): array
    {
        $out = [
            'errors' => [],
            'warnings' => [],
            'wxr_version' => '',
            'base_url' => '',
            'site_title' => '',
            'authors' => [],
            'categories' => [],
            'tags' => [],
            'terms' => [],
            'items' => [],
        ];

        $xml = trim($xml);
        if ($xml === '') {
            $out['errors'][] = 'Empty XML.';

            return $out;
        }

        // Strip UTF-8 BOM.
        if (str_starts_with($xml, "\xEF\xBB\xBF")) {
            $xml = substr($xml, 3);
        }

        if (!self::isWxr($xml) && stripos($xml, '<rss') === false && stripos($xml, '<channel') === false) {
            $out['errors'][] = 'Not a WordPress WXR export (missing WXR markers).';

            return $out;
        }

        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = @$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $libErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded || $dom->documentElement === null) {
            $msg = 'Invalid XML.';
            if ($libErrors !== []) {
                $first = $libErrors[0];
                $msg .= ' ' . trim($first->message) . ' (line ' . $first->line . ')';
            }
            $out['errors'][] = $msg;

            return $out;
        }

        $xp = new DOMXPath($dom);
        // Register common namespaces (version-agnostic wp: uses export/*).
        $xp->registerNamespace('content', self::NS_CONTENT);
        $xp->registerNamespace('dc', self::NS_DC);
        $xp->registerNamespace('excerpt', self::NS_EXCERPT);
        $xp->registerNamespace('wp', 'http://wordpress.org/export/1.2/');
        // Also try 1.1 / 1.0 if nodes empty — fallback via local-name.

        $channel = $xp->query('//channel')->item(0);
        if ($channel === null) {
            $out['errors'][] = 'WXR channel element not found.';

            return $out;
        }

        $out['site_title'] = self::firstText($xp, 'title', $channel);
        $out['wxr_version'] = self::wpText($xp, 'wxr_version', $channel);
        $base = self::wpText($xp, 'base_blog_url', $channel);
        if ($base === '') {
            $base = self::wpText($xp, 'base_site_url', $channel);
        }
        if ($base === '') {
            $base = self::firstText($xp, 'link', $channel);
        }
        $out['base_url'] = $base;

        // Authors
        foreach (self::wpElements($xp, 'author', $channel) as $node) {
            $out['authors'][] = [
                'author_id' => (int) self::wpText($xp, 'author_id', $node),
                'author_login' => self::wpText($xp, 'author_login', $node),
                'author_email' => self::wpText($xp, 'author_email', $node),
                'author_display_name' => self::wpText($xp, 'author_display_name', $node),
                'author_first_name' => self::wpText($xp, 'author_first_name', $node),
                'author_last_name' => self::wpText($xp, 'author_last_name', $node),
            ];
        }

        // Categories
        foreach (self::wpElements($xp, 'category', $channel) as $node) {
            // Channel-level wp:category (not item category).
            if ($node->parentNode === null || $node->parentNode->nodeName === 'item') {
                continue;
            }
            $out['categories'][] = [
                'term_id' => self::wpText($xp, 'term_id', $node),
                'slug' => self::sanitizeSlug(self::wpText($xp, 'category_nicename', $node)),
                'parent' => self::sanitizeSlug(self::wpText($xp, 'category_parent', $node)),
                'name' => self::wpText($xp, 'cat_name', $node),
                'description' => self::wpText($xp, 'category_description', $node),
            ];
        }

        // Tags
        foreach (self::wpElements($xp, 'tag', $channel) as $node) {
            $out['tags'][] = [
                'term_id' => self::wpText($xp, 'term_id', $node),
                'slug' => self::sanitizeSlug(self::wpText($xp, 'tag_slug', $node)),
                'name' => self::wpText($xp, 'tag_name', $node),
                'description' => self::wpText($xp, 'tag_description', $node),
            ];
        }

        // Custom terms
        foreach (self::wpElements($xp, 'term', $channel) as $node) {
            $out['terms'][] = [
                'term_id' => self::wpText($xp, 'term_id', $node),
                'taxonomy' => self::wpText($xp, 'term_taxonomy', $node),
                'slug' => self::sanitizeSlug(self::wpText($xp, 'term_slug', $node)),
                'name' => self::wpText($xp, 'term_name', $node),
                'description' => self::wpText($xp, 'term_description', $node),
                'parent' => self::wpText($xp, 'term_parent', $node),
            ];
        }

        // Items
        foreach ($xp->query('item', $channel) ?: [] as $itemNode) {
            if (!$itemNode instanceof DOMElement) {
                continue;
            }
            $item = [
                'title' => self::firstText($xp, 'title', $itemNode),
                'link' => self::firstText($xp, 'link', $itemNode),
                'pubDate' => self::firstText($xp, 'pubDate', $itemNode),
                'creator' => self::textByLocalName($itemNode, 'creator', 'dc'),
                'guid' => self::firstText($xp, 'guid', $itemNode),
                'description' => self::firstText($xp, 'description', $itemNode),
                'content' => self::textByLocalName($itemNode, 'encoded', 'content'),
                'excerpt' => self::textByLocalName($itemNode, 'encoded', 'excerpt'),
                'post_id' => (int) self::wpText($xp, 'post_id', $itemNode),
                'post_date' => self::wpText($xp, 'post_date', $itemNode),
                'post_date_gmt' => self::wpText($xp, 'post_date_gmt', $itemNode),
                'post_modified' => self::wpText($xp, 'post_modified', $itemNode),
                'post_modified_gmt' => self::wpText($xp, 'post_modified_gmt', $itemNode),
                'comment_status' => self::wpText($xp, 'comment_status', $itemNode),
                'ping_status' => self::wpText($xp, 'ping_status', $itemNode),
                'post_name' => self::wpText($xp, 'post_name', $itemNode),
                'status' => self::wpText($xp, 'status', $itemNode),
                'post_parent' => (int) self::wpText($xp, 'post_parent', $itemNode),
                'menu_order' => (int) self::wpText($xp, 'menu_order', $itemNode),
                'post_type' => self::wpText($xp, 'post_type', $itemNode),
                'post_password' => self::wpText($xp, 'post_password', $itemNode),
                'is_sticky' => (int) self::wpText($xp, 'is_sticky', $itemNode),
                'attachment_url' => self::wpText($xp, 'attachment_url', $itemNode),
                'post_mime_type' => self::wpText($xp, 'post_mime_type', $itemNode),
                'categories' => [],
                'postmeta' => [],
                'comments' => [],
            ];

            // Item categories (RSS category with domain/nicename).
            foreach ($xp->query('category', $itemNode) ?: [] as $catNode) {
                if (!$catNode instanceof DOMElement) {
                    continue;
                }
                // Skip namespaced wp:category if any appear under item.
                if ($catNode->namespaceURI !== null && str_contains($catNode->namespaceURI, 'wordpress.org/export')) {
                    continue;
                }
                $item['categories'][] = [
                    'domain' => $catNode->getAttribute('domain') !== '' ? $catNode->getAttribute('domain') : 'category',
                    'nicename' => $catNode->getAttribute('nicename'),
                    'name' => trim($catNode->textContent ?? ''),
                ];
            }

            foreach (self::wpElements($xp, 'postmeta', $itemNode) as $metaNode) {
                $item['postmeta'][] = [
                    'key' => self::wpText($xp, 'meta_key', $metaNode),
                    'value' => self::wpText($xp, 'meta_value', $metaNode),
                ];
            }

            foreach (self::wpElements($xp, 'comment', $itemNode) as $cNode) {
                $item['comments'][] = [
                    'comment_id' => (int) self::wpText($xp, 'comment_id', $cNode),
                    'comment_author' => self::wpText($xp, 'comment_author', $cNode),
                    'comment_author_email' => self::wpText($xp, 'comment_author_email', $cNode),
                    'comment_author_url' => self::wpText($xp, 'comment_author_url', $cNode),
                    'comment_author_IP' => self::wpText($xp, 'comment_author_IP', $cNode),
                    'comment_date' => self::wpText($xp, 'comment_date', $cNode),
                    'comment_date_gmt' => self::wpText($xp, 'comment_date_gmt', $cNode),
                    'comment_content' => self::wpText($xp, 'comment_content', $cNode),
                    'comment_approved' => self::wpText($xp, 'comment_approved', $cNode),
                    'comment_type' => self::wpText($xp, 'comment_type', $cNode),
                    'comment_parent' => (int) self::wpText($xp, 'comment_parent', $cNode),
                    'comment_user_id' => (int) self::wpText($xp, 'comment_user_id', $cNode),
                    'user_id' => (int) self::wpText($xp, 'comment_user_id', $cNode),
                    'comment_agent' => self::wpText($xp, 'comment_agent', $cNode),
                ];
            }

            $out['items'][] = $item;
        }

        if ($out['wxr_version'] === '' && $out['items'] === [] && $out['authors'] === [] && $out['categories'] === []) {
            $out['warnings'][] = 'WXR version not found; proceeding with best-effort parse.';
        }

        return $out;
    }

    /**
     * Maximum upload / import size in bytes.
     */
    public static function maxBytes(): int
    {
        if (defined('AP_WXR_MAX_BYTES') && is_int(AP_WXR_MAX_BYTES) && AP_WXR_MAX_BYTES > 0) {
            return AP_WXR_MAX_BYTES;
        }
        $filter = self::DEFAULT_MAX_BYTES;
        if (function_exists('ap_apply_filters')) {
            $filter = (int) ap_apply_filters('ap_wxr_max_bytes', $filter);
        }

        return $filter > 0 ? $filter : self::DEFAULT_MAX_BYTES;
    }

    /**
     * Human-readable byte size.
     */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KiB';
        }

        return round($bytes / 1048576, 1) . ' MiB';
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function emptyResult(): array
    {
        return [
            'ok' => false,
            'authors' => 0,
            'authors_created' => 0,
            'authors_mapped' => 0,
            'categories' => 0,
            'tags' => 0,
            'terms' => 0,
            'posts' => 0,
            'pages' => 0,
            'attachments' => 0,
            'other_posts' => 0,
            'comments' => 0,
            'skipped' => 0,
            'wxr_version' => '',
            'base_url' => '',
            'site_title' => '',
            'errors' => [],
            'warnings' => [],
            'author_map' => [],
            'post_map' => [],
        ];
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }
        throw new RuntimeException('No database connection available for WXR import.');
    }

    /**
     * @param array<string, mixed> $author
     * @param array<string, mixed> $result
     *
     * @return array{user_id: int, login: string, wp_id: int, created: bool}
     */
    private static function importAuthor(array $author, AP_DB $db, array &$result): array
    {
        $login = AP_User::sanitizeUserLogin((string) ($author['author_login'] ?? ''));
        $email = AP_User::sanitizeEmail((string) ($author['author_email'] ?? ''));
        $wpId = (int) ($author['author_id'] ?? 0);
        $display = trim((string) ($author['author_display_name'] ?? ''));

        if ($login === '' && $email === '') {
            $result['warnings'][] = 'Skipped author with empty login and email.';

            return ['user_id' => 0, 'login' => '', 'wp_id' => $wpId, 'created' => false];
        }

        $existing = null;
        if ($login !== '') {
            $existing = AP_User::getByLogin($login, $db);
        }
        if ($existing === null && $email !== '') {
            $existing = AP_User::getByEmail($email, $db);
        }

        if ($existing !== null) {
            $result['authors']++;
            $result['authors_mapped']++;
            if ($wpId > 0) {
                AP_User::updateMeta((int) $existing->ID, self::META_WXR_AUTHOR_ID, (string) $wpId, $db);
            }

            return [
                'user_id' => (int) $existing->ID,
                'login' => $existing->user_login,
                'wp_id' => $wpId,
                'created' => false,
            ];
        }

        if ($login === '') {
            $login = 'imported_' . ($wpId > 0 ? (string) $wpId : substr(md5($email), 0, 8));
            $login = AP_User::sanitizeUserLogin($login);
        }
        if ($email === '' || !AP_User::isValidEmail($email)) {
            // Placeholder email so create() succeeds; user should update later.
            $safeLogin = preg_replace('/[^a-z0-9._-]/i', '', $login) ?: 'user';
            $email = $safeLogin . '@imported.invalid';
        }

        $password = AP_User::generatePassword(20);
        $created = AP_User::create([
            'user_login' => $login,
            'user_email' => $email,
            'user_pass' => $password,
            'display_name' => $display !== '' ? $display : $login,
            'first_name' => (string) ($author['author_first_name'] ?? ''),
            'last_name' => (string) ($author['author_last_name'] ?? ''),
            'role' => 'author',
        ], $db);

        if (!$created['ok'] || $created['id'] < 1) {
            $err = $created['errors'] !== [] ? implode(' ', $created['errors']) : 'unknown error';
            $result['warnings'][] = 'Could not create author "' . $login . '": ' . $err;

            return ['user_id' => 0, 'login' => $login, 'wp_id' => $wpId, 'created' => false];
        }

        $uid = (int) $created['id'];
        if ($wpId > 0) {
            AP_User::updateMeta($uid, self::META_WXR_AUTHOR_ID, (string) $wpId, $db);
        }
        AP_User::updateMeta($uid, '_ap_wxr_needs_password_reset', '1', $db);

        $result['authors']++;
        $result['authors_created']++;

        return ['user_id' => $uid, 'login' => $login, 'wp_id' => $wpId, 'created' => true];
    }

    /**
     * Ensure a term exists; returns term_id or 0.
     *
     * @param array<string, mixed> $data
     */
    private static function ensureTerm(string $name, string $taxonomy, array $data, AP_DB $db): int
    {
        $slug = isset($data['slug']) ? self::sanitizeSlug((string) $data['slug']) : self::sanitizeSlug($name);
        if ($slug !== '') {
            $existing = AP_Taxonomy::getTermBySlug($slug, $taxonomy, $db);
            if ($existing !== null) {
                return (int) $existing->term_id;
            }
        }
        $byName = AP_Taxonomy::getTermByName($name, $taxonomy, $db);
        if ($byName !== null) {
            return (int) $byName->term_id;
        }

        $inserted = AP_Taxonomy::insertTerm($name, $taxonomy, $data, $db);
        if (is_array($inserted)) {
            return (int) $inserted['term_id'];
        }

        return 0;
    }

    private static function mapPostStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'publish', 'published' => 'publish',
            'draft' => 'draft',
            'pending' => 'pending',
            'private' => 'private',
            'future' => 'future',
            'trash' => 'trash',
            'auto-draft' => 'auto-draft',
            'inherit' => 'inherit',
            '' => 'draft',
            default => self::sanitizeKey($status) !== '' ? self::sanitizeKey($status) : 'draft',
        };
    }

    private static function mapCommentApproved(string $approved): string
    {
        $approved = strtolower(trim($approved));

        return match ($approved) {
            '1', 'approve', 'approved', 'true' => AP_Comment::STATUS_APPROVED,
            '0', 'hold', 'pending', 'false', 'unapproved' => AP_Comment::STATUS_HOLD,
            'spam' => AP_Comment::STATUS_SPAM,
            'trash' => AP_Comment::STATUS_TRASH,
            default => AP_Comment::STATUS_HOLD,
        };
    }

    private static function normalizeDatetime(string $value): string
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return gmdate('Y-m-d H:i:s');
        }
        // Already MySQL-ish.
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return gmdate('Y-m-d H:i:s');
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }

    private static function sanitizeKey(string $key): string
    {
        if (function_exists('ap_sanitize_key')) {
            return ap_sanitize_key($key);
        }
        $key = strtolower($key);

        return (string) preg_replace('/[^a-z0-9_\-]/', '', $key);
    }

    private static function sanitizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = (string) preg_replace('/[^a-z0-9_\-]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug;
    }

    private static function firstText(DOMXPath $xp, string $name, DOMNode $ctx): string
    {
        $n = $xp->query($name, $ctx)?->item(0);

        return $n !== null ? trim((string) $n->textContent) : '';
    }

    /**
     * Read a wp:* child using local-name (version-agnostic namespaces).
     */
    private static function wpText(DOMXPath $xp, string $localName, DOMNode $ctx): string
    {
        // Prefer registered wp namespace, fall back to local-name.
        $n = $xp->query('wp:' . $localName, $ctx)?->item(0);
        if ($n !== null) {
            return trim((string) $n->textContent);
        }
        $n = $xp->query('.//*[local-name()="' . $localName . '"]', $ctx)?->item(0);

        return $n !== null ? trim((string) $n->textContent) : '';
    }

    /**
     * @return list<DOMElement>
     */
    private static function wpElements(DOMXPath $xp, string $localName, DOMNode $ctx): array
    {
        $list = [];
        $nodes = $xp->query('wp:' . $localName, $ctx);
        if ($nodes === false || $nodes->length === 0) {
            $nodes = $xp->query('./*[local-name()="' . $localName . '"]', $ctx);
        }
        if ($nodes === false) {
            return [];
        }
        foreach ($nodes as $n) {
            if ($n instanceof DOMElement) {
                $list[] = $n;
            }
        }

        return $list;
    }

    private static function textByLocalName(DOMElement $parent, string $localName, string $prefixHint = ''): string
    {
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === $localName) {
                if ($prefixHint !== '' && $child->prefix !== '' && $child->prefix !== $prefixHint) {
                    // Keep scanning for a better match, but accept first if only one.
                }

                return trim((string) $child->textContent);
            }
        }
        // Prefix match fallback.
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->nodeName === $prefixHint . ':' . $localName || str_ends_with($child->nodeName, ':' . $localName)) {
                return trim((string) $child->textContent);
            }
        }

        return '';
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the allowed size.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on the server.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write the file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the upload.',
            default => 'Upload failed (error code ' . $code . ').',
        };
    }
}
