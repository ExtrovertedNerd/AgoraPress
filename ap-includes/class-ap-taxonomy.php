<?php

/**
 * AgoraPress taxonomies — categories, tags, custom taxonomies, term CRUD.
 *
 * WP-inspired (not a fork). Built-in taxonomies: category (hierarchical),
 * post_tag (flat). Terms live in {prefix}terms; membership + hierarchy +
 * counts in {prefix}term_taxonomy; object links in {prefix}term_relationships.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Taxonomy registry + term / relationship helpers.
 */
class AP_Taxonomy
{
    /** Option key storing the default category term_id. */
    public const OPTION_DEFAULT_CATEGORY = 'default_category';

    /** Slug for the built-in Uncategorized category. */
    public const UNCATEGORIZED_SLUG = 'uncategorized';

    /** @var array<string, array<string, mixed>> Registered taxonomies. */
    private static array $taxonomies = [];

    /** @var bool Whether built-ins have been registered this process. */
    private static bool $builtinsRegistered = false;

    // -------------------------------------------------------------------------
    // Registry
    // -------------------------------------------------------------------------

    /**
     * Ensure built-in taxonomies are registered.
     */
    public static function ensureBuiltins(): void
    {
        if (self::$builtinsRegistered) {
            return;
        }

        self::registerBuiltinTaxonomies();
        self::$builtinsRegistered = true;
    }

    /**
     * Clear registry (tests only). Re-registers builtins on next ensure.
     */
    public static function resetRegistry(): void
    {
        self::$taxonomies = [];
        self::$builtinsRegistered = false;
    }

    /**
     * Register a taxonomy.
     *
     * Args (all optional): label, labels (array), public, hierarchical,
     * show_ui, show_admin_column, object_type (list of post types), rewrite,
     * query_var, description.
     *
     * @param array<string, mixed> $args
     */
    public static function register(string $taxonomy, array $args = []): void
    {
        $taxonomy = self::sanitizeKey($taxonomy);
        if ($taxonomy === '') {
            return;
        }

        $defaults = [
            'label' => $taxonomy,
            'labels' => [],
            'public' => true,
            'hierarchical' => false,
            'show_ui' => null,
            'show_admin_column' => null,
            'object_type' => ['post'],
            'rewrite' => true,
            'query_var' => true,
            'description' => '',
        ];

        $merged = array_merge($defaults, $args, ['name' => $taxonomy]);
        $public = !empty($merged['public']);
        if ($merged['show_ui'] === null) {
            $merged['show_ui'] = $public;
        }
        if ($merged['show_admin_column'] === null) {
            $merged['show_admin_column'] = !empty($merged['hierarchical']);
        }
        if (!is_array($merged['object_type'])) {
            $merged['object_type'] = [(string) $merged['object_type']];
        }
        $merged['object_type'] = array_values(array_filter(
            array_map(static fn ($t): string => self::sanitizeKey((string) $t), $merged['object_type']),
            static fn (string $t): bool => $t !== ''
        ));

        // Derive labels.
        $label = (string) $merged['label'];
        $singular = is_array($merged['labels']) && isset($merged['labels']['singular_name'])
            ? (string) $merged['labels']['singular_name']
            : (str_ends_with($label, 's') && !str_ends_with($label, 'ss')
                ? substr($label, 0, -1)
                : $label);
        $labelDefaults = [
            'name' => $label,
            'singular_name' => $singular,
            'search_items' => 'Search ' . $label,
            'all_items' => 'All ' . $label,
            'edit_item' => 'Edit ' . $singular,
            'update_item' => 'Update ' . $singular,
            'add_new_item' => 'Add New ' . $singular,
            'new_item_name' => 'New ' . $singular . ' Name',
            'parent_item' => 'Parent ' . $singular,
            'parent_item_colon' => 'Parent ' . $singular . ':',
            'menu_name' => $label,
        ];
        $labels = is_array($merged['labels']) ? $merged['labels'] : [];
        $merged['labels'] = array_merge($labelDefaults, $labels);

        self::$taxonomies[$taxonomy] = $merged;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getObject(string $taxonomy): ?array
    {
        self::ensureBuiltins();
        $taxonomy = self::sanitizeKey($taxonomy);

        return self::$taxonomies[$taxonomy] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getTaxonomies(): array
    {
        self::ensureBuiltins();

        return self::$taxonomies;
    }

    public static function exists(string $taxonomy): bool
    {
        self::ensureBuiltins();

        return isset(self::$taxonomies[self::sanitizeKey($taxonomy)]);
    }

    public static function isHierarchical(string $taxonomy): bool
    {
        $obj = self::getObject($taxonomy);

        return $obj !== null && !empty($obj['hierarchical']);
    }

    /**
     * Taxonomies registered for a post type.
     *
     * @return list<string>
     */
    public static function getObjectTaxonomies(string $postType): array
    {
        self::ensureBuiltins();
        $postType = self::sanitizeKey($postType);
        $out = [];
        foreach (self::$taxonomies as $name => $obj) {
            $types = $obj['object_type'] ?? [];
            if (is_array($types) && in_array($postType, $types, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Built-in category + post_tag.
     */
    public static function registerBuiltinTaxonomies(): void
    {
        self::register('category', [
            'label' => 'Categories',
            'labels' => [
                'singular_name' => 'Category',
                'menu_name' => 'Categories',
            ],
            'public' => true,
            'hierarchical' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'object_type' => ['post'],
            'query_var' => 'category_name',
            'rewrite' => ['slug' => 'category'],
        ]);
        self::register('post_tag', [
            'label' => 'Tags',
            'labels' => [
                'singular_name' => 'Tag',
                'menu_name' => 'Tags',
            ],
            'public' => true,
            'hierarchical' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'object_type' => ['post'],
            'query_var' => 'tag',
            'rewrite' => ['slug' => 'tag'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Term hydration
    // -------------------------------------------------------------------------

    /**
     * Normalize a joined term + term_taxonomy row into a plain object.
     *
     * @param object|array<string, mixed> $row
     */
    public static function termFromRow(object|array $row): object
    {
        $r = is_array($row) ? (object) $row : $row;

        $term = new stdClass();
        $term->term_id = (int) ($r->term_id ?? 0);
        $term->name = (string) ($r->name ?? '');
        $term->slug = (string) ($r->slug ?? '');
        $term->term_group = (int) ($r->term_group ?? 0);
        $term->term_taxonomy_id = (int) ($r->term_taxonomy_id ?? 0);
        $term->taxonomy = (string) ($r->taxonomy ?? '');
        $term->description = (string) ($r->description ?? '');
        $term->parent = (int) ($r->parent ?? 0);
        $term->count = (int) ($r->count ?? 0);

        return $term;
    }

    // -------------------------------------------------------------------------
    // Term CRUD
    // -------------------------------------------------------------------------

    /**
     * Insert a term into a taxonomy.
     *
     * Data keys: name (required), slug, description, parent, term_group.
     *
     * @param array<string, mixed> $data
     *
     * @return array{term_id: int, term_taxonomy_id: int}|int Term pair on success, 0 on failure.
     */
    public static function insertTerm(
        string $name,
        string $taxonomy,
        array $data = [],
        ?AP_DB $db = null
    ): array|int {
        self::ensureBuiltins();
        $taxonomy = self::sanitizeKey($taxonomy);
        if (!self::exists($taxonomy)) {
            return 0;
        }

        $name = trim($name);
        if ($name === '') {
            return 0;
        }

        $db = self::resolveDb($db);
        $slug = isset($data['slug']) && is_string($data['slug']) && trim($data['slug']) !== ''
            ? self::sanitizeSlug(trim($data['slug']))
            : self::sanitizeSlug($name);
        $slug = self::uniqueTermSlug($slug, $taxonomy, 0, $db);

        $description = isset($data['description']) ? (string) $data['description'] : '';
        $parent = self::isHierarchical($taxonomy)
            ? max(0, (int) ($data['parent'] ?? 0))
            : 0;
        $termGroup = (int) ($data['term_group'] ?? 0);

        if ($parent > 0 && self::getTerm($parent, $taxonomy, $db) === null) {
            return 0;
        }

        // Reuse existing term row with same slug when not already in this taxonomy.
        $existingTermId = (int) ($db->getVar(
            'SELECT term_id FROM ' . $db->quoteIdentifier($db->table('terms'))
            . ' WHERE slug = ? LIMIT 1',
            [$slug]
        ) ?? 0);

        if ($existingTermId > 0) {
            $already = self::getTerm($existingTermId, $taxonomy, $db);
            if ($already !== null) {
                // Same taxonomy + slug → treat as existing (idempotent for name match).
                return [
                    'term_id' => (int) $already->term_id,
                    'term_taxonomy_id' => (int) $already->term_taxonomy_id,
                ];
            }
            $termId = $existingTermId;
        } else {
            $n = $db->insert('terms', [
                'name' => $name,
                'slug' => $slug,
                'term_group' => $termGroup,
            ]);
            if ($n < 1) {
                return 0;
            }
            $termId = (int) $db->lastInsertId();
            if ($termId < 1) {
                return 0;
            }
        }

        // Update name if we re-used a shared term row under a new taxonomy.
        if ($existingTermId > 0) {
            $db->update('terms', ['name' => $name], ['term_id' => $termId]);
        }

        $n = $db->insert('term_taxonomy', [
            'term_id' => $termId,
            'taxonomy' => $taxonomy,
            'description' => $description,
            'parent' => $parent,
            'count' => 0,
        ]);
        if ($n < 1) {
            return 0;
        }
        $ttId = (int) $db->lastInsertId();
        if ($ttId < 1) {
            return 0;
        }

        return [
            'term_id' => $termId,
            'term_taxonomy_id' => $ttId,
        ];
    }

    /**
     * Update an existing term.
     *
     * @param array<string, mixed> $data name, slug, description, parent
     */
    public static function updateTerm(
        int $termId,
        string $taxonomy,
        array $data,
        ?AP_DB $db = null
    ): bool {
        self::ensureBuiltins();
        $taxonomy = self::sanitizeKey($taxonomy);
        if ($termId < 1 || !self::exists($taxonomy)) {
            return false;
        }

        $db = self::resolveDb($db);
        $term = self::getTerm($termId, $taxonomy, $db);
        if ($term === null) {
            return false;
        }

        $termFields = [];
        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return false;
            }
            $termFields['name'] = $name;
        }
        if (isset($data['slug'])) {
            $slug = self::sanitizeSlug((string) $data['slug']);
            if ($slug === '') {
                $slug = self::sanitizeSlug((string) ($termFields['name'] ?? $term->name));
            }
            $termFields['slug'] = self::uniqueTermSlug($slug, $taxonomy, $termId, $db);
        }
        if (isset($data['term_group'])) {
            $termFields['term_group'] = (int) $data['term_group'];
        }

        if ($termFields !== []) {
            $db->update('terms', $termFields, ['term_id' => $termId]);
        }

        $taxFields = [];
        if (array_key_exists('description', $data)) {
            $taxFields['description'] = (string) $data['description'];
        }
        if (array_key_exists('parent', $data) && self::isHierarchical($taxonomy)) {
            $parent = max(0, (int) $data['parent']);
            if ($parent === $termId) {
                return false;
            }
            if ($parent > 0) {
                if (self::getTerm($parent, $taxonomy, $db) === null) {
                    return false;
                }
                if (self::wouldCreateCycle($termId, $parent, $taxonomy, $db)) {
                    return false;
                }
            }
            $taxFields['parent'] = $parent;
        }

        if ($taxFields !== []) {
            $db->update(
                'term_taxonomy',
                $taxFields,
                ['term_id' => $termId, 'taxonomy' => $taxonomy]
            );
        }

        return true;
    }

    /**
     * Delete a term from a taxonomy (and orphan term row if unused).
     *
     * Default category cannot be deleted; objects using it are reassigned to
     * the default category when deleting another category.
     */
    public static function deleteTerm(
        int $termId,
        string $taxonomy,
        ?AP_DB $db = null
    ): bool {
        self::ensureBuiltins();
        $taxonomy = self::sanitizeKey($taxonomy);
        if ($termId < 1 || !self::exists($taxonomy)) {
            return false;
        }

        $db = self::resolveDb($db);
        $term = self::getTerm($termId, $taxonomy, $db);
        if ($term === null) {
            return false;
        }

        if ($taxonomy === 'category') {
            $defaultId = self::getDefaultCategoryId($db);
            if ($defaultId === $termId) {
                return false;
            }
        }

        $ttId = (int) $term->term_taxonomy_id;

        // Reassign children to this term's parent (hierarchical).
        if (self::isHierarchical($taxonomy)) {
            $children = $db->getCol(
                'SELECT term_id FROM ' . $db->quoteIdentifier($db->table('term_taxonomy'))
                . ' WHERE taxonomy = ? AND parent = ?',
                [$taxonomy, $termId]
            );
            foreach ($children as $childId) {
                $db->update(
                    'term_taxonomy',
                    ['parent' => (int) $term->parent],
                    ['term_id' => (int) $childId, 'taxonomy' => $taxonomy]
                );
            }
        }

        // Objects linked to this term.
        $objectIds = $db->getCol(
            'SELECT object_id FROM ' . $db->quoteIdentifier($db->table('term_relationships'))
            . ' WHERE term_taxonomy_id = ?',
            [$ttId]
        );

        $db->delete('term_relationships', ['term_taxonomy_id' => $ttId]);
        $db->delete('term_taxonomy', ['term_taxonomy_id' => $ttId]);

        // Drop shared terms row if no other taxonomy uses it.
        $stillUsed = (int) ($db->getVar(
            'SELECT COUNT(*) FROM ' . $db->quoteIdentifier($db->table('term_taxonomy'))
            . ' WHERE term_id = ?',
            [$termId]
        ) ?? 0);
        if ($stillUsed < 1) {
            $db->delete('terms', ['term_id' => $termId]);
        }

        // Reassign posts that lost their only category to default.
        if ($taxonomy === 'category' && $objectIds !== []) {
            $defaultId = self::getDefaultCategoryId($db);
            foreach ($objectIds as $objectId) {
                $objectId = (int) $objectId;
                $remaining = self::getObjectTerms($objectId, 'category', ['fields' => 'ids'], $db);
                if ($remaining === [] && $defaultId > 0) {
                    self::setObjectTerms($objectId, [$defaultId], 'category', false, $db);
                } else {
                    $current = self::getObjectTerms($objectId, 'category', [], $db);
                    $ttIds = array_map(
                        static function ($t): int {
                            return (int) (is_object($t) ? $t->term_taxonomy_id : $t);
                        },
                        $current
                    );
                    self::updateTermCount($ttIds, $db);
                }
            }
        } else {
            // Recount is zero for deleted term; no-op for remaining.
        }

        return true;
    }

    /**
     * Get a term by ID (optionally scoped to a taxonomy).
     */
    public static function getTerm(
        int $termId,
        string $taxonomy = '',
        ?AP_DB $db = null
    ): ?object {
        if ($termId < 1) {
            return null;
        }

        $db = self::resolveDb($db);
        $terms = $db->quoteIdentifier($db->table('terms'));
        $tax = $db->quoteIdentifier($db->table('term_taxonomy'));

        $sql = 'SELECT t.term_id, t.name, t.slug, t.term_group,'
            . ' tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count'
            . ' FROM ' . $terms . ' t'
            . ' INNER JOIN ' . $tax . ' tt ON t.term_id = tt.term_id'
            . ' WHERE t.term_id = ?';
        $params = [$termId];

        $taxonomy = self::sanitizeKey($taxonomy);
        if ($taxonomy !== '') {
            $sql .= ' AND tt.taxonomy = ?';
            $params[] = $taxonomy;
        }
        $sql .= ' LIMIT 1';

        $row = $db->getRow($sql, $params);

        return $row !== null ? self::termFromRow($row) : null;
    }

    /**
     * Get a term by slug within a taxonomy.
     */
    public static function getTermBySlug(
        string $slug,
        string $taxonomy,
        ?AP_DB $db = null
    ): ?object {
        $slug = self::sanitizeSlug($slug);
        $taxonomy = self::sanitizeKey($taxonomy);
        if ($slug === '' || $taxonomy === '') {
            return null;
        }

        $db = self::resolveDb($db);
        $terms = $db->quoteIdentifier($db->table('terms'));
        $tax = $db->quoteIdentifier($db->table('term_taxonomy'));

        $row = $db->getRow(
            'SELECT t.term_id, t.name, t.slug, t.term_group,'
            . ' tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count'
            . ' FROM ' . $terms . ' t'
            . ' INNER JOIN ' . $tax . ' tt ON t.term_id = tt.term_id'
            . ' WHERE t.slug = ? AND tt.taxonomy = ? LIMIT 1',
            [$slug, $taxonomy]
        );

        return $row !== null ? self::termFromRow($row) : null;
    }

    /**
     * Get a term by name within a taxonomy (case-sensitive exact match).
     */
    public static function getTermByName(
        string $name,
        string $taxonomy,
        ?AP_DB $db = null
    ): ?object {
        $name = trim($name);
        $taxonomy = self::sanitizeKey($taxonomy);
        if ($name === '' || $taxonomy === '') {
            return null;
        }

        $db = self::resolveDb($db);
        $terms = $db->quoteIdentifier($db->table('terms'));
        $tax = $db->quoteIdentifier($db->table('term_taxonomy'));

        $row = $db->getRow(
            'SELECT t.term_id, t.name, t.slug, t.term_group,'
            . ' tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count'
            . ' FROM ' . $terms . ' t'
            . ' INNER JOIN ' . $tax . ' tt ON t.term_id = tt.term_id'
            . ' WHERE t.name = ? AND tt.taxonomy = ? LIMIT 1',
            [$name, $taxonomy]
        );

        return $row !== null ? self::termFromRow($row) : null;
    }

    /**
     * List terms for a taxonomy.
     *
     * Args: hide_empty (bool), parent (int|''), search (string), orderby
     * (name|slug|count|term_id), order (ASC|DESC), number (int), offset (int),
     * hierarchical (bool — include nested structure metadata only),
     * include / exclude (list of term ids), fields (all|ids|names|id=>name).
     *
     * @param array<string, mixed> $args
     *
     * @return list<object>|list<int>|list<string>|array<int, string>
     */
    public static function getTerms(
        string $taxonomy,
        array $args = [],
        ?AP_DB $db = null
    ): array {
        self::ensureBuiltins();
        $taxonomy = self::sanitizeKey($taxonomy);
        if (!self::exists($taxonomy) && $taxonomy !== '') {
            return [];
        }

        $db = self::resolveDb($db);
        $terms = $db->quoteIdentifier($db->table('terms'));
        $tax = $db->quoteIdentifier($db->table('term_taxonomy'));

        $hideEmpty = !empty($args['hide_empty']);
        $parent = array_key_exists('parent', $args) ? $args['parent'] : '';
        $search = isset($args['search']) ? trim((string) $args['search']) : '';
        $orderby = (string) ($args['orderby'] ?? 'name');
        $order = strtoupper((string) ($args['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $number = isset($args['number']) ? max(0, (int) $args['number']) : 0;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
        $include = self::normalizeIdList($args['include'] ?? []);
        $exclude = self::normalizeIdList($args['exclude'] ?? []);
        $fields = (string) ($args['fields'] ?? 'all');

        $where = ['tt.taxonomy = ?'];
        $params = [$taxonomy];

        if ($hideEmpty) {
            $where[] = 'tt.count > 0';
        }
        if ($parent !== '' && $parent !== null) {
            $where[] = 'tt.parent = ?';
            $params[] = (int) $parent;
        }
        if ($search !== '') {
            $where[] = '(t.name LIKE ? OR t.slug LIKE ?)';
            $like = '%' . self::escapeLike($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($include !== []) {
            $ph = implode(', ', array_fill(0, count($include), '?'));
            $where[] = 't.term_id IN (' . $ph . ')';
            foreach ($include as $id) {
                $params[] = $id;
            }
        }
        if ($exclude !== []) {
            $ph = implode(', ', array_fill(0, count($exclude), '?'));
            $where[] = 't.term_id NOT IN (' . $ph . ')';
            foreach ($exclude as $id) {
                $params[] = $id;
            }
        }

        $orderMap = [
            'name' => 't.name',
            'slug' => 't.slug',
            'count' => 'tt.count',
            'term_id' => 't.term_id',
            'id' => 't.term_id',
            'parent' => 'tt.parent',
        ];
        $orderCol = $orderMap[$orderby] ?? 't.name';

        $sql = 'SELECT t.term_id, t.name, t.slug, t.term_group,'
            . ' tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count'
            . ' FROM ' . $terms . ' t'
            . ' INNER JOIN ' . $tax . ' tt ON t.term_id = tt.term_id'
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $orderCol . ' ' . $order . ', t.term_id ASC';

        if ($number > 0) {
            $sql .= ' LIMIT ' . $number . ' OFFSET ' . $offset;
        }

        $rows = $db->getResults($sql, $params);
        $list = [];
        foreach ($rows as $row) {
            $list[] = self::termFromRow($row);
        }

        return self::formatTermFields($list, $fields);
    }

    /**
     * Count terms in a taxonomy (respects same filters as getTerms minus limit).
     *
     * @param array<string, mixed> $args
     */
    public static function countTerms(
        string $taxonomy,
        array $args = [],
        ?AP_DB $db = null
    ): int {
        $args['fields'] = 'ids';
        $args['number'] = 0;
        $args['offset'] = 0;
        $ids = self::getTerms($taxonomy, $args, $db);

        return count($ids);
    }

    /**
     * Nested tree for hierarchical taxonomies.
     *
     * @param array<string, mixed> $args Passed to getTerms (parent forced per level).
     *
     * @return list<array{term: object, children: list<array<string, mixed>>}>
     */
    public static function getTermTree(
        string $taxonomy,
        array $args = [],
        int $parent = 0,
        ?AP_DB $db = null
    ): array {
        $args['parent'] = $parent;
        $args['fields'] = 'all';
        /** @var list<object> $terms */
        $terms = self::getTerms($taxonomy, $args, $db);
        $tree = [];
        foreach ($terms as $term) {
            $tree[] = [
                'term' => $term,
                'children' => self::getTermTree($taxonomy, $args, (int) $term->term_id, $db),
            ];
        }

        return $tree;
    }

    /**
     * Ancestor term IDs from parent toward root.
     *
     * @return list<int>
     */
    public static function getAncestorIds(
        int $termId,
        string $taxonomy,
        ?AP_DB $db = null
    ): array {
        $db = self::resolveDb($db);
        $ancestors = [];
        $current = self::getTerm($termId, $taxonomy, $db);
        $guard = 0;
        while ($current !== null && (int) $current->parent > 0 && $guard < 100) {
            $parentId = (int) $current->parent;
            $ancestors[] = $parentId;
            $current = self::getTerm($parentId, $taxonomy, $db);
            $guard++;
        }

        return $ancestors;
    }

    // -------------------------------------------------------------------------
    // Object relationships
    // -------------------------------------------------------------------------

    /**
     * Assign terms to an object (post). Replaces existing terms for the taxonomy
     * unless $append is true.
     *
     * $terms may be term IDs, slugs, or names (names create terms when needed
     * for non-hierarchical taxonomies; hierarchical prefers IDs/slugs).
     *
     * @param list<int|string>|int|string $terms
     *
     * @return list<int> Term IDs set on the object
     */
    public static function setObjectTerms(
        int $objectId,
        array|int|string $terms,
        string $taxonomy,
        bool $append = false,
        ?AP_DB $db = null
    ): array {
        self::ensureBuiltins();
        $taxonomy = self::sanitizeKey($taxonomy);
        if ($objectId < 1 || !self::exists($taxonomy)) {
            return [];
        }

        $db = self::resolveDb($db);
        $termIds = self::resolveTermInputs($terms, $taxonomy, $db);

        /** @var list<object> $previous */
        $previous = self::getObjectTerms($objectId, $taxonomy, ['fields' => 'all'], $db);

        if (!$append) {
            foreach ($previous as $ex) {
                if (!in_array((int) $ex->term_id, $termIds, true)) {
                    $db->delete('term_relationships', [
                        'object_id' => $objectId,
                        'term_taxonomy_id' => (int) $ex->term_taxonomy_id,
                    ]);
                }
            }
        }

        $order = 0;
        $ttIdsForCount = [];
        foreach ($termIds as $termId) {
            $term = self::getTerm($termId, $taxonomy, $db);
            if ($term === null) {
                continue;
            }
            $ttId = (int) $term->term_taxonomy_id;
            $exists = (int) ($db->getVar(
                'SELECT 1 FROM ' . $db->quoteIdentifier($db->table('term_relationships'))
                . ' WHERE object_id = ? AND term_taxonomy_id = ? LIMIT 1',
                [$objectId, $ttId]
            ) ?? 0);
            if ($exists < 1) {
                $db->insert('term_relationships', [
                    'object_id' => $objectId,
                    'term_taxonomy_id' => $ttId,
                    'term_order' => $order,
                ]);
            }
            $ttIdsForCount[] = $ttId;
            $order++;
        }

        // Recount all terms that may have changed (previous + newly assigned).
        $toRecount = $ttIdsForCount;
        foreach ($previous as $ex) {
            $toRecount[] = (int) $ex->term_taxonomy_id;
        }
        self::updateTermCount(array_values(array_unique($toRecount)), $db);

        // Categories: ensure at least the default category when empty after replace.
        if ($taxonomy === 'category' && !$append) {
            $final = self::getObjectTerms($objectId, 'category', ['fields' => 'ids'], $db);
            if ($final === []) {
                $defaultId = self::getDefaultCategoryId($db);
                if ($defaultId > 0) {
                    return self::setObjectTerms($objectId, [$defaultId], 'category', false, $db);
                }
            }

            return array_map('intval', $final);
        }

        /** @var list<int> $ids */
        $ids = self::getObjectTerms($objectId, $taxonomy, ['fields' => 'ids'], $db);

        return $ids;
    }

    /**
     * Terms assigned to an object.
     *
     * @param array<string, mixed> $args fields: all|ids|names|slugs
     *
     * @return list<object>|list<int>|list<string>
     */
    public static function getObjectTerms(
        int $objectId,
        string $taxonomy = '',
        array $args = [],
        ?AP_DB $db = null
    ): array {
        if ($objectId < 1) {
            return [];
        }

        $db = self::resolveDb($db);
        $terms = $db->quoteIdentifier($db->table('terms'));
        $tax = $db->quoteIdentifier($db->table('term_taxonomy'));
        $rel = $db->quoteIdentifier($db->table('term_relationships'));

        $sql = 'SELECT t.term_id, t.name, t.slug, t.term_group,'
            . ' tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count'
            . ' FROM ' . $rel . ' tr'
            . ' INNER JOIN ' . $tax . ' tt ON tr.term_taxonomy_id = tt.term_taxonomy_id'
            . ' INNER JOIN ' . $terms . ' t ON tt.term_id = t.term_id'
            . ' WHERE tr.object_id = ?';
        $params = [$objectId];

        $taxonomy = self::sanitizeKey($taxonomy);
        if ($taxonomy !== '') {
            $sql .= ' AND tt.taxonomy = ?';
            $params[] = $taxonomy;
        }
        $sql .= ' ORDER BY tr.term_order ASC, t.name ASC';

        $rows = $db->getResults($sql, $params);
        $list = [];
        foreach ($rows as $row) {
            $list[] = self::termFromRow($row);
        }

        $fields = (string) ($args['fields'] ?? 'all');

        return self::formatTermFields($list, $fields);
    }

    /**
     * Remove specific terms from an object.
     *
     * @param list<int|string>|int|string $terms
     */
    public static function removeObjectTerms(
        int $objectId,
        array|int|string $terms,
        string $taxonomy,
        ?AP_DB $db = null
    ): bool {
        self::ensureBuiltins();
        $taxonomy = self::sanitizeKey($taxonomy);
        if ($objectId < 1 || !self::exists($taxonomy)) {
            return false;
        }

        $db = self::resolveDb($db);
        $termIds = self::resolveTermInputs($terms, $taxonomy, $db, false);
        $ttIds = [];
        foreach ($termIds as $termId) {
            $term = self::getTerm($termId, $taxonomy, $db);
            if ($term === null) {
                continue;
            }
            $ttId = (int) $term->term_taxonomy_id;
            $db->delete('term_relationships', [
                'object_id' => $objectId,
                'term_taxonomy_id' => $ttId,
            ]);
            $ttIds[] = $ttId;
        }
        self::updateTermCount($ttIds, $db);

        if ($taxonomy === 'category') {
            $remaining = self::getObjectTerms($objectId, 'category', ['fields' => 'ids'], $db);
            if ($remaining === []) {
                $defaultId = self::getDefaultCategoryId($db);
                if ($defaultId > 0) {
                    self::setObjectTerms($objectId, [$defaultId], 'category', false, $db);
                }
            }
        }

        return true;
    }

    /**
     * Object IDs that have any of the given terms.
     *
     * @param list<int> $termIds Term IDs (not term_taxonomy_ids)
     * @param array<string, mixed> $args taxonomy, operator (IN|AND|NOT IN)
     *
     * @return list<int>
     */
    public static function getObjectsInTerm(
        array $termIds,
        array $args = [],
        ?AP_DB $db = null
    ): array {
        $termIds = self::normalizeIdList($termIds);
        if ($termIds === []) {
            return [];
        }

        $db = self::resolveDb($db);
        $tax = $db->quoteIdentifier($db->table('term_taxonomy'));
        $rel = $db->quoteIdentifier($db->table('term_relationships'));
        $taxonomy = self::sanitizeKey((string) ($args['taxonomy'] ?? ''));
        $operator = strtoupper((string) ($args['operator'] ?? 'IN'));
        if (!in_array($operator, ['IN', 'AND', 'NOT IN'], true)) {
            $operator = 'IN';
        }

        // Resolve term_ids → term_taxonomy_ids.
        $ph = implode(', ', array_fill(0, count($termIds), '?'));
        $ttSql = 'SELECT term_taxonomy_id FROM ' . $tax . ' WHERE term_id IN (' . $ph . ')';
        $ttParams = $termIds;
        if ($taxonomy !== '') {
            $ttSql .= ' AND taxonomy = ?';
            $ttParams[] = $taxonomy;
        }
        $ttIds = array_map('intval', $db->getCol($ttSql, $ttParams));
        if ($ttIds === []) {
            return [];
        }

        if ($operator === 'AND') {
            // Objects that have ALL of the terms.
            $ph2 = implode(', ', array_fill(0, count($ttIds), '?'));
            $sql = 'SELECT object_id FROM ' . $rel
                . ' WHERE term_taxonomy_id IN (' . $ph2 . ')'
                . ' GROUP BY object_id HAVING COUNT(DISTINCT term_taxonomy_id) = ?';
            $params = array_merge($ttIds, [count($ttIds)]);
            $ids = $db->getCol($sql, $params);

            return array_map('intval', $ids);
        }

        if ($operator === 'NOT IN') {
            // Objects that have none of the terms (among those that have any taxonomy terms).
            $ph2 = implode(', ', array_fill(0, count($ttIds), '?'));
            $sql = 'SELECT DISTINCT object_id FROM ' . $rel
                . ' WHERE object_id NOT IN ('
                . ' SELECT object_id FROM ' . $rel
                . ' WHERE term_taxonomy_id IN (' . $ph2 . ')'
                . ')';
            $ids = $db->getCol($sql, $ttIds);

            return array_map('intval', $ids);
        }

        $ph2 = implode(', ', array_fill(0, count($ttIds), '?'));
        $ids = $db->getCol(
            'SELECT DISTINCT object_id FROM ' . $rel
            . ' WHERE term_taxonomy_id IN (' . $ph2 . ')',
            $ttIds
        );

        return array_map('intval', $ids);
    }

    /**
     * Recalculate count for term_taxonomy rows.
     *
     * @param list<int> $termTaxonomyIds
     */
    public static function updateTermCount(array $termTaxonomyIds, ?AP_DB $db = null): void
    {
        $db = self::resolveDb($db);
        $rel = $db->quoteIdentifier($db->table('term_relationships'));
        foreach ($termTaxonomyIds as $ttId) {
            $ttId = (int) $ttId;
            if ($ttId < 1) {
                continue;
            }
            $count = (int) ($db->getVar(
                'SELECT COUNT(*) FROM ' . $rel . ' WHERE term_taxonomy_id = ?',
                [$ttId]
            ) ?? 0);
            $db->update('term_taxonomy', ['count' => $count], ['term_taxonomy_id' => $ttId]);
        }
    }

    // -------------------------------------------------------------------------
    // Default category / seeding
    // -------------------------------------------------------------------------

    /**
     * Ensure Uncategorized exists and default_category option points at it.
     *
     * Safe to call repeatedly (idempotent).
     */
    public static function ensureDefaultCategory(?AP_DB $db = null): int
    {
        self::ensureBuiltins();
        $db = self::resolveDb($db);

        $existing = self::getTermBySlug(self::UNCATEGORIZED_SLUG, 'category', $db);
        if ($existing !== null) {
            $id = (int) $existing->term_id;
            self::persistDefaultCategoryOption($id, $db);

            return $id;
        }

        $result = self::insertTerm('Uncategorized', 'category', [
            'slug' => self::UNCATEGORIZED_SLUG,
            'description' => '',
        ], $db);
        if (!is_array($result)) {
            return 0;
        }
        $id = (int) $result['term_id'];
        self::persistDefaultCategoryOption($id, $db);

        return $id;
    }

    /**
     * Default category term_id (creates Uncategorized if missing).
     */
    public static function getDefaultCategoryId(?AP_DB $db = null): int
    {
        $db = self::resolveDb($db);
        $stored = self::readDefaultCategoryOption($db);
        if ($stored > 0) {
            $term = self::getTerm($stored, 'category', $db);
            if ($term !== null) {
                return $stored;
            }
        }

        return self::ensureDefaultCategory($db);
    }

    // -------------------------------------------------------------------------
    // Slug helpers
    // -------------------------------------------------------------------------

    /**
     * Sanitize a title into a URL slug (delegates to AP_Post when available).
     */
    public static function sanitizeSlug(string $title): string
    {
        if (class_exists('AP_Post', false) && method_exists('AP_Post', 'sanitizeSlug')) {
            return AP_Post::sanitizeSlug($title);
        }

        $title = trim($title);
        if ($title === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            $title = mb_strtolower($title, 'UTF-8');
        } else {
            $title = strtolower($title);
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
            if (is_string($converted) && $converted !== '') {
                $title = strtolower($converted);
            }
        }
        $title = preg_replace('/[^a-z0-9]+/', '-', $title) ?? '';
        $title = trim($title, '-');
        if (strlen($title) > 200) {
            $title = rtrim(substr($title, 0, 200), '-');
        }

        return $title;
    }

    /**
     * Unique slug among terms in the same taxonomy.
     */
    public static function uniqueTermSlug(
        string $slug,
        string $taxonomy,
        int $excludeTermId = 0,
        ?AP_DB $db = null
    ): string {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            $slug = 'term';
        }

        $db = self::resolveDb($db);
        $terms = $db->quoteIdentifier($db->table('terms'));
        $tax = $db->quoteIdentifier($db->table('term_taxonomy'));
        $taxonomy = self::sanitizeKey($taxonomy);

        $base = $slug;
        $suffix = 2;
        while (true) {
            $sql = 'SELECT t.term_id FROM ' . $terms . ' t'
                . ' INNER JOIN ' . $tax . ' tt ON t.term_id = tt.term_id'
                . ' WHERE t.slug = ? AND tt.taxonomy = ?';
            $params = [$slug, $taxonomy];
            if ($excludeTermId > 0) {
                $sql .= ' AND t.term_id != ?';
                $params[] = $excludeTermId;
            }
            $sql .= ' LIMIT 1';
            $found = $db->getVar($sql, $params);
            if ($found === null) {
                return $slug;
            }
            $slug = $base . '-' . $suffix;
            $suffix++;
            if ($suffix > 1000) {
                return $base . '-' . bin2hex(random_bytes(3));
            }
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function wouldCreateCycle(
        int $termId,
        int $newParent,
        string $taxonomy,
        AP_DB $db
    ): bool {
        if ($newParent === $termId) {
            return true;
        }
        $ancestors = self::getAncestorIds($newParent, $taxonomy, $db);

        return in_array($termId, $ancestors, true);
    }

    /**
     * @param list<int|string>|int|string $terms
     *
     * @return list<int>
     */
    private static function resolveTermInputs(
        array|int|string $terms,
        string $taxonomy,
        AP_DB $db,
        bool $createMissing = true
    ): array {
        if (!is_array($terms)) {
            $terms = [$terms];
        }

        $ids = [];
        $hierarchical = self::isHierarchical($taxonomy);

        foreach ($terms as $item) {
            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                $id = (int) $item;
                if ($id > 0 && self::getTerm($id, $taxonomy, $db) !== null) {
                    $ids[] = $id;
                }
                continue;
            }

            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }

            // Prefer slug match, then name.
            $bySlug = self::getTermBySlug(self::sanitizeSlug($item), $taxonomy, $db);
            if ($bySlug !== null) {
                $ids[] = (int) $bySlug->term_id;
                continue;
            }
            $byName = self::getTermByName($item, $taxonomy, $db);
            if ($byName !== null) {
                $ids[] = (int) $byName->term_id;
                continue;
            }

            // Create on the fly for flat taxonomies (tags); hierarchical needs explicit create.
            if ($createMissing && !$hierarchical) {
                $created = self::insertTerm($item, $taxonomy, [], $db);
                if (is_array($created)) {
                    $ids[] = (int) $created['term_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<object> $list
     *
     * @return list<object>|list<int>|list<string>|array<int, string>
     */
    private static function formatTermFields(array $list, string $fields): array
    {
        return match ($fields) {
            'ids' => array_map(static fn (object $t): int => (int) $t->term_id, $list),
            'names' => array_map(static fn (object $t): string => (string) $t->name, $list),
            'slugs' => array_map(static fn (object $t): string => (string) $t->slug, $list),
            'id=>name' => (static function (array $list): array {
                $map = [];
                foreach ($list as $t) {
                    $map[(int) $t->term_id] = (string) $t->name;
                }

                return $map;
            })($list),
            default => $list,
        };
    }

    /**
     * @return list<int>
     */
    private static function normalizeIdList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (is_int($value)) {
            return $value > 0 ? [$value] : [];
        }
        if (is_string($value)) {
            $parts = preg_split('/[\s,]+/', $value) ?: [];
            $out = [];
            foreach ($parts as $p) {
                $id = (int) $p;
                if ($id > 0) {
                    $out[] = $id;
                }
            }

            return array_values(array_unique($out));
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $id = (int) $item;
            if ($id > 0) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private static function sanitizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';

        return $key;
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }
        throw new RuntimeException('No database connection available for taxonomies.');
    }

    private static function persistDefaultCategoryOption(int $termId, AP_DB $db): void
    {
        $name = self::OPTION_DEFAULT_CATEGORY;
        $existing = $db->getVar(
            'SELECT option_id FROM ' . $db->quoteIdentifier($db->table('options'))
            . ' WHERE option_name = ? LIMIT 1',
            [$name]
        );
        if ($existing !== null) {
            $db->update(
                'options',
                ['option_value' => (string) $termId],
                ['option_name' => $name]
            );
        } else {
            $db->insert('options', [
                'option_name' => $name,
                'option_value' => (string) $termId,
                'autoload' => 'yes',
            ]);
        }
    }

    private static function readDefaultCategoryOption(AP_DB $db): int
    {
        $val = $db->getVar(
            'SELECT option_value FROM ' . $db->quoteIdentifier($db->table('options'))
            . ' WHERE option_name = ? LIMIT 1',
            [self::OPTION_DEFAULT_CATEGORY]
        );

        return $val !== null ? max(0, (int) $val) : 0;
    }
}
