<?php

/**
 * Admin screens for managing taxonomy terms (categories, tags, custom).
 *
 * Handles list table rendering, add/edit/delete, and bulk delete for
 * hierarchical and flat taxonomies.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Term list + form save/render for edit-tags.php.
 */
class AP_Admin_Terms
{
    /**
     * Resolve taxonomy query arg (must be registered and show_ui).
     */
    public static function resolveTaxonomy(string $raw, string $default = 'category'): string
    {
        $tax = strtolower(trim($raw));
        $tax = preg_replace('/[^a-z0-9_\-]/', '', $tax) ?? '';
        if ($tax === '' || !AP_Taxonomy::exists($tax)) {
            return $default;
        }
        $obj = AP_Taxonomy::getObject($tax);
        if ($obj === null || empty($obj['show_ui'])) {
            return $default;
        }

        return $tax;
    }

    /**
     * Human label for a taxonomy.
     */
    public static function taxonomyLabel(string $taxonomy, bool $singular = false): string
    {
        $obj = AP_Taxonomy::getObject($taxonomy);
        if ($obj === null) {
            return $singular ? 'Term' : 'Terms';
        }
        if ($singular) {
            $labels = is_array($obj['labels'] ?? null) ? $obj['labels'] : [];

            return (string) ($labels['singular_name'] ?? $obj['label'] ?? $taxonomy);
        }

        return (string) ($obj['label'] ?? $taxonomy);
    }

    /**
     * Save (insert or update) a term from form input.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, id: int, message_key: string, errors: list<string>, term: ?object}
     */
    public static function save(array $input, int $userId, ?AP_DB $db = null): array
    {
        AP_Taxonomy::ensureBuiltins();
        $db = $db ?? ap_db();

        $taxonomy = self::resolveTaxonomy((string) ($input['taxonomy'] ?? 'category'));
        $termId = (int) ($input['tag_ID'] ?? $input['term_id'] ?? 0);
        $isNew = $termId < 1;

        $nonceAction = $isNew ? 'add-tag' : 'update-tag-' . $termId;
        $nonce = (string) ($input['_ap_nonce'] ?? '');
        if (!ap_check_nonce($nonce, $nonceAction, $userId > 0 ? $userId : null)) {
            return [
                'ok' => false,
                'id' => $termId,
                'message_key' => 'nonce',
                'errors' => ['Security check failed. Please reload and try again.'],
                'term' => $termId > 0 ? AP_Taxonomy::getTerm($termId, $taxonomy, $db) : null,
            ];
        }

        if (!AP_Admin::userCan($userId, 'manage_categories', null, $db)) {
            return [
                'ok' => false,
                'id' => $termId,
                'message_key' => 'error',
                'errors' => ['You do not have permission to manage terms.'],
                'term' => $termId > 0 ? AP_Taxonomy::getTerm($termId, $taxonomy, $db) : null,
            ];
        }

        $name = ap_sanitize_text_field((string) ($input['name'] ?? ''));
        $slug = ap_sanitize_text_field((string) ($input['slug'] ?? ''));
        $description = ap_sanitize_textarea_field((string) ($input['description'] ?? ''));
        $parent = (int) ($input['parent'] ?? 0);

        $errors = [];
        if ($name === '') {
            $errors[] = 'Name is required.';

            return [
                'ok' => false,
                'id' => $termId,
                'message_key' => 'error',
                'errors' => $errors,
                'term' => $termId > 0 ? AP_Taxonomy::getTerm($termId, $taxonomy, $db) : null,
            ];
        }

        $data = [
            'description' => $description,
        ];
        if ($slug !== '') {
            $data['slug'] = $slug;
        }
        if (AP_Taxonomy::isHierarchical($taxonomy)) {
            $data['parent'] = max(0, $parent);
        }

        if ($isNew) {
            $result = AP_Taxonomy::insertTerm($name, $taxonomy, $data, $db);
            if (!is_array($result)) {
                return [
                    'ok' => false,
                    'id' => 0,
                    'message_key' => 'error',
                    'errors' => ['Could not create the term.'],
                    'term' => null,
                ];
            }

            return [
                'ok' => true,
                'id' => (int) $result['term_id'],
                'message_key' => 'term_created',
                'errors' => [],
                'term' => AP_Taxonomy::getTerm((int) $result['term_id'], $taxonomy, $db),
            ];
        }

        $existing = AP_Taxonomy::getTerm($termId, $taxonomy, $db);
        if ($existing === null) {
            return [
                'ok' => false,
                'id' => $termId,
                'message_key' => 'not_found',
                'errors' => ['That term could not be found.'],
                'term' => null,
            ];
        }

        $data['name'] = $name;
        $ok = AP_Taxonomy::updateTerm($termId, $taxonomy, $data, $db);
        if (!$ok) {
            return [
                'ok' => false,
                'id' => $termId,
                'message_key' => 'error',
                'errors' => ['Could not update the term (invalid parent?).'],
                'term' => $existing,
            ];
        }

        return [
            'ok' => true,
            'id' => $termId,
            'message_key' => 'term_updated',
            'errors' => [],
            'term' => AP_Taxonomy::getTerm($termId, $taxonomy, $db),
        ];
    }

    /**
     * Delete a single term (row action).
     *
     * @return array{ok: bool, message_key: string}
     */
    public static function delete(int $termId, string $taxonomy, int $userId, string $nonce, ?AP_DB $db = null): array
    {
        $db = $db ?? ap_db();
        $taxonomy = self::resolveTaxonomy($taxonomy);
        if (!ap_check_nonce($nonce, 'delete-tag-' . $termId, $userId > 0 ? $userId : null)) {
            return ['ok' => false, 'message_key' => 'nonce'];
        }
        if (!AP_Admin::userCan($userId, 'manage_categories', null, $db)) {
            return ['ok' => false, 'message_key' => 'error'];
        }
        if ($termId < 1) {
            return ['ok' => false, 'message_key' => 'not_found'];
        }
        $ok = AP_Taxonomy::deleteTerm($termId, $taxonomy, $db);

        return [
            'ok' => $ok,
            'message_key' => $ok ? 'term_deleted' : 'error',
        ];
    }

    /**
     * Bulk delete terms.
     *
     * @param list<int> $ids
     *
     * @return array{ok: bool, count: int, message_key: string}
     */
    /**
     * @param list<int> $ids
     *
     * @return array{ok: bool, count: int, message_key: string}
     */
    public static function bulkDelete(
        array $ids,
        string $taxonomy,
        int $userId,
        string $nonce,
        ?AP_DB $db = null
    ): array {
        $db = $db ?? ap_db();
        $taxonomy = self::resolveTaxonomy($taxonomy);
        if (!ap_check_nonce($nonce, 'bulk-tags', $userId > 0 ? $userId : null)) {
            return ['ok' => false, 'count' => 0, 'message_key' => 'nonce'];
        }
        if (!AP_Admin::userCan($userId, 'manage_categories', null, $db)) {
            return ['ok' => false, 'count' => 0, 'message_key' => 'error'];
        }
        $count = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && AP_Taxonomy::deleteTerm($id, $taxonomy, $db)) {
                $count++;
            }
        }

        return [
            'ok' => $count > 0,
            'count' => $count,
            'message_key' => $count > 0 ? 'bulk_term_deleted' : 'error',
        ];
    }

    /**
     * Render add-new term form (left column on edit-tags).
     */
    public static function renderAddForm(string $taxonomy, int $userId = 0, ?AP_DB $db = null): string
    {
        $db = $db ?? ap_db();
        $taxonomy = self::resolveTaxonomy($taxonomy);
        $singular = self::taxonomyLabel($taxonomy, true);
        $hierarchical = AP_Taxonomy::isHierarchical($taxonomy);

        $html = '<div class="ap-term-add">';
        $html .= '<h2>Add New ' . ap_esc_html($singular) . '</h2>';
        $html .= '<form method="post" action="' . ap_esc_url(AP_Admin::url('edit-tags.php', [
            'taxonomy' => $taxonomy,
        ])) . '" class="ap-term-form">';
        $html .= ap_nonce_field('add-tag', '_ap_nonce', false);
        $html .= '<input type="hidden" name="taxonomy" value="' . ap_esc_attr($taxonomy) . '" />';
        $html .= '<input type="hidden" name="action" value="add-tag" />';

        $html .= '<div class="ap-field"><label for="tag-name">Name</label><br />';
        $html .= '<input type="text" name="name" id="tag-name" class="regular-text" required />';
        $html .= '<p class="description">The name is how it appears on your site.</p></div>';

        $html .= '<div class="ap-field"><label for="tag-slug">Slug</label><br />';
        $html .= '<input type="text" name="slug" id="tag-slug" class="regular-text" />';
        $html .= '<p class="description">The “slug” is the URL-friendly version of the name.</p></div>';

        if ($hierarchical) {
            $html .= '<div class="ap-field"><label for="parent">Parent</label><br />';
            $html .= self::renderParentSelect($taxonomy, 0, 0, $db);
            $html .= '</div>';
        }

        $html .= '<div class="ap-field"><label for="tag-description">Description</label><br />';
        $html .= '<textarea name="description" id="tag-description" rows="4" class="large-text"></textarea>';
        $html .= '</div>';

        $html .= '<p class="submit"><button type="submit" class="button button-primary">Add New '
            . ap_esc_html($singular) . '</button></p>';
        $html .= '</form></div>';

        unset($userId);

        return $html;
    }

    /**
     * Render edit term form.
     */
    public static function renderEditForm(
        object $term,
        string $taxonomy,
        int $userId = 0,
        ?AP_DB $db = null
    ): string {
        $db = $db ?? ap_db();
        $taxonomy = self::resolveTaxonomy($taxonomy);
        $singular = self::taxonomyLabel($taxonomy, true);
        $hierarchical = AP_Taxonomy::isHierarchical($taxonomy);
        $id = (int) $term->term_id;

        $html = '<div class="ap-term-edit">';
        $html .= '<h2>Edit ' . ap_esc_html($singular) . '</h2>';
        $html .= '<form method="post" action="' . ap_esc_url(AP_Admin::url('edit-tags.php', [
            'taxonomy' => $taxonomy,
            'action' => 'edit',
            'tag_ID' => $id,
        ])) . '" class="ap-term-form">';
        $html .= ap_nonce_field('update-tag-' . $id, '_ap_nonce', false);
        $html .= '<input type="hidden" name="taxonomy" value="' . ap_esc_attr($taxonomy) . '" />';
        $html .= '<input type="hidden" name="tag_ID" value="' . $id . '" />';
        $html .= '<input type="hidden" name="action" value="editedtag" />';

        $html .= '<div class="ap-field"><label for="tag-name">Name</label><br />';
        $html .= '<input type="text" name="name" id="tag-name" class="regular-text" value="'
            . ap_esc_attr((string) $term->name) . '" required /></div>';

        $html .= '<div class="ap-field"><label for="tag-slug">Slug</label><br />';
        $html .= '<input type="text" name="slug" id="tag-slug" class="regular-text" value="'
            . ap_esc_attr((string) $term->slug) . '" /></div>';

        if ($hierarchical) {
            $html .= '<div class="ap-field"><label for="parent">Parent</label><br />';
            $html .= self::renderParentSelect($taxonomy, (int) $term->parent, $id, $db);
            $html .= '</div>';
        }

        $html .= '<div class="ap-field"><label for="tag-description">Description</label><br />';
        $html .= '<textarea name="description" id="tag-description" rows="4" class="large-text">'
            . ap_esc_textarea((string) $term->description) . '</textarea></div>';

        $html .= '<p class="submit">';
        $html .= '<button type="submit" class="button button-primary">Update</button> ';
        $cancel = AP_Admin::url('edit-tags.php', ['taxonomy' => $taxonomy]);
        $html .= '<a class="button" href="' . ap_esc_url($cancel) . '">Cancel</a>';
        $html .= '</p></form></div>';

        unset($userId);

        return $html;
    }

    /**
     * Render terms list table.
     *
     * @param array<string, mixed> $request
     */
    public static function renderListTable(
        string $taxonomy,
        array $request = [],
        int $userId = 0,
        ?AP_DB $db = null
    ): string {
        $db = $db ?? ap_db();
        $taxonomy = self::resolveTaxonomy($taxonomy);
        $hierarchical = AP_Taxonomy::isHierarchical($taxonomy);
        $search = ap_sanitize_text_field((string) ($request['s'] ?? ''));
        $paged = max(1, (int) ($request['paged'] ?? 1));
        $perPage = 20;

        $args = [
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
            'search' => $search,
        ];

        // Hierarchical: show all (tree-ish flat list with indent); flat: paginate.
        if (!$hierarchical) {
            $args['number'] = $perPage;
            $args['offset'] = ($paged - 1) * $perPage;
        }

        /** @var list<object> $items */
        $items = AP_Taxonomy::getTerms($taxonomy, $args, $db);
        $total = AP_Taxonomy::countTerms($taxonomy, [
            'hide_empty' => false,
            'search' => $search,
        ], $db);
        $totalPages = $hierarchical ? 1 : max(1, (int) ceil($total / $perPage));

        $actionUrl = ap_esc_url(AP_Admin::url('edit-tags.php', ['taxonomy' => $taxonomy]));

        $html = '<form method="get" action="' . $actionUrl . '" class="ap-search-form ap-term-search">';
        $html .= '<input type="hidden" name="taxonomy" value="' . ap_esc_attr($taxonomy) . '" />';
        $html .= '<label class="screen-reader-text" for="ap-term-search">Search</label>';
        $html .= '<input type="search" id="ap-term-search" name="s" value="'
            . ap_esc_attr($search) . '" placeholder="Search…" />';
        $html .= '<button type="submit" class="button">Search</button></form>';

        $html .= '<form method="post" action="' . $actionUrl . '" class="ap-list-table-form">';
        $html .= ap_nonce_field('bulk-tags', '_ap_nonce', false);
        $html .= '<input type="hidden" name="taxonomy" value="' . ap_esc_attr($taxonomy) . '" />';
        $html .= '<div class="ap-tablenav ap-tablenav-top">';
        $html .= '<select name="action"><option value="-1">Bulk actions</option>';
        $html .= '<option value="delete">Delete</option></select> ';
        $html .= '<button type="submit" class="button">Apply</button>';
        $html .= self::renderPagination($paged, $totalPages, $total, $taxonomy, $search);
        $html .= '</div>';

        $html .= '<table class="ap-list-table widefat striped">';
        $html .= '<thead><tr>';
        $html .= '<th scope="col" class="check-column">'
            . '<input type="checkbox" id="cb-select-all" aria-label="Select all" />'
            . '</th>';
        $html .= '<th scope="col">Name</th>';
        $html .= '<th scope="col">Slug</th>';
        if ($hierarchical) {
            $html .= '<th scope="col">Parent</th>';
        }
        $html .= '<th scope="col">Description</th>';
        $html .= '<th scope="col">Count</th>';
        $html .= '</tr></thead><tbody>';

        if ($items === []) {
            $cols = $hierarchical ? 6 : 5;
            $html .= '<tr class="no-items"><td colspan="' . $cols . '">No items found.</td></tr>';
        } else {
            $parentNames = [];
            if ($hierarchical) {
                foreach ($items as $t) {
                    $parentNames[(int) $t->term_id] = (string) $t->name;
                }
            }
            foreach ($items as $term) {
                $html .= self::renderRow($term, $taxonomy, $hierarchical, $parentNames, $userId);
            }
        }

        $html .= '</tbody></table>';
        $html .= '<div class="ap-tablenav ap-tablenav-bottom">';
        $html .= '<select name="action2"><option value="-1">Bulk actions</option>';
        $html .= '<option value="delete">Delete</option></select> ';
        $html .= '<button type="submit" class="button">Apply</button>';
        $html .= self::renderPagination($paged, $totalPages, $total, $taxonomy, $search);
        $html .= '</div></form>';

        return $html;
    }

    /**
     * @param array<int, string> $parentNames
     */
    private static function renderRow(
        object $term,
        string $taxonomy,
        bool $hierarchical,
        array $parentNames,
        int $userId
    ): string {
        $id = (int) $term->term_id;
        $editUrl = AP_Admin::url('edit-tags.php', [
            'taxonomy' => $taxonomy,
            'action' => 'edit',
            'tag_ID' => $id,
        ]);
        $deleteUrl = AP_Admin::url('edit-tags.php', [
            'taxonomy' => $taxonomy,
            'action' => 'delete',
            'tag_ID' => $id,
            '_ap_nonce' => ap_create_nonce('delete-tag-' . $id, $userId > 0 ? $userId : null),
        ]);
        $name = (string) $term->name !== '' ? (string) $term->name : '(no name)';
        $indent = '';
        if ($hierarchical && (int) $term->parent > 0) {
            $depth = count(AP_Taxonomy::getAncestorIds($id, $taxonomy));
            if ($depth > 0) {
                $indent = '<span class="ap-page-indent" style="padding-left:'
                    . ($depth * 1.25) . 'em"></span>';
            }
        }

        $isDefault = $taxonomy === 'category'
            && $id === AP_Taxonomy::getDefaultCategoryId();

        $row = '<tr id="tag-' . $id . '">';
        $row .= '<th scope="row" class="check-column">';
        if (!$isDefault) {
            $row .= '<input type="checkbox" name="delete_tags[]" value="' . $id . '" />';
        }
        $row .= '</th>';
        $row .= '<td class="column-name" data-colname="Name">' . $indent
            . '<strong><a class="row-title" href="' . ap_esc_url($editUrl) . '">'
            . ap_esc_html($name) . '</a></strong>';
        if ($isDefault) {
            $row .= ' <span class="ap-muted">— Default</span>';
        }
        $row .= '<div class="row-actions">';
        $row .= '<span class="edit"><a href="' . ap_esc_url($editUrl) . '">Edit</a></span>';
        if (!$isDefault) {
            $row .= ' | <span class="delete"><a class="submitdelete" href="'
                . ap_esc_url($deleteUrl) . '">Delete</a></span>';
        }
        $row .= '</div></td>';
        $row .= '<td class="column-slug" data-colname="Slug">'
            . ap_esc_html((string) $term->slug) . '</td>';
        if ($hierarchical) {
            $parentLabel = '—';
            $pid = (int) $term->parent;
            if ($pid > 0) {
                $parentLabel = $parentNames[$pid] ?? ('#' . $pid);
            }
            $row .= '<td class="column-parent" data-colname="Parent">'
                . ap_esc_html($parentLabel) . '</td>';
        }
        $desc = (string) $term->description;
        if (strlen($desc) > 80) {
            $desc = substr($desc, 0, 77) . '…';
        }
        $row .= '<td class="column-description" data-colname="Description">'
            . ap_esc_html($desc) . '</td>';
        $row .= '<td class="column-count" data-colname="Count">'
            . (int) $term->count . '</td>';
        $row .= '</tr>';

        return $row;
    }

    private static function renderPagination(
        int $paged,
        int $totalPages,
        int $total,
        string $taxonomy,
        string $search
    ): string {
        $html = '<div class="ap-pagination"><span class="displaying-num">'
            . (int) $total . ' item' . ($total === 1 ? '' : 's') . '</span>';
        if ($totalPages > 1) {
            $html .= ' <span class="ap-paging-links">';
            for ($i = 1; $i <= $totalPages; $i++) {
                $url = AP_Admin::url('edit-tags.php', array_filter([
                    'taxonomy' => $taxonomy,
                    'paged' => $i > 1 ? $i : null,
                    's' => $search !== '' ? $search : null,
                ]));
                if ($i === $paged) {
                    $html .= ' <span class="current">' . $i . '</span>';
                } else {
                    $html .= ' <a href="' . ap_esc_url($url) . '">' . $i . '</a>';
                }
            }
            $html .= '</span>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Parent dropdown for hierarchical taxonomies.
     */
    public static function renderParentSelect(
        string $taxonomy,
        int $selected = 0,
        int $excludeId = 0,
        ?AP_DB $db = null
    ): string {
        $db = $db ?? ap_db();
        $html = '<select name="parent" id="parent">';
        $html .= '<option value="0">— None —</option>';
        $tree = AP_Taxonomy::getTermTree($taxonomy, [
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ], 0, $db);
        $html .= self::renderParentOptions($tree, $selected, $excludeId, 0);
        $html .= '</select>';

        return $html;
    }

    /**
     * @param list<array{term: object, children: list<array<string, mixed>>}> $tree
     */
    private static function renderParentOptions(
        array $tree,
        int $selected,
        int $excludeId,
        int $depth
    ): string {
        $html = '';
        foreach ($tree as $node) {
            $term = $node['term'];
            $id = (int) $term->term_id;
            if ($id === $excludeId) {
                continue;
            }
            $pad = str_repeat('— ', $depth);
            $sel = $selected === $id ? ' selected' : '';
            $html .= '<option value="' . $id . '"' . $sel . '>'
                . ap_esc_html($pad . (string) $term->name) . '</option>';
            if (!empty($node['children']) && is_array($node['children'])) {
                /** @var list<array{term: object, children: list<array<string, mixed>>}> $children */
                $children = $node['children'];
                $html .= self::renderParentOptions($children, $selected, $excludeId, $depth + 1);
            }
        }

        return $html;
    }

    /**
     * Category checklist for post edit sidebar.
     *
     * @param list<int> $selectedIds
     */
    public static function renderCategoryChecklist(
        array $selectedIds = [],
        ?AP_DB $db = null
    ): string {
        $db = $db ?? ap_db();
        AP_Taxonomy::ensureDefaultCategory($db);
        $tree = AP_Taxonomy::getTermTree('category', [
            'hide_empty' => false,
            'orderby' => 'name',
        ], 0, $db);

        $html = '<div class="ap-category-checklist">';
        $html .= self::renderChecklistItems($tree, $selectedIds, 0);
        $html .= '</div>';

        return $html;
    }

    /**
     * @param list<array{term: object, children: list<array<string, mixed>>}> $tree
     * @param list<int> $selectedIds
     */
    private static function renderChecklistItems(
        array $tree,
        array $selectedIds,
        int $depth
    ): string {
        if ($tree === []) {
            return '<p class="description">No categories yet.</p>';
        }
        $html = '<ul class="ap-cat-checklist' . ($depth > 0 ? ' children' : '') . '">';
        foreach ($tree as $node) {
            $term = $node['term'];
            $id = (int) $term->term_id;
            $checked = in_array($id, $selectedIds, true) ? ' checked' : '';
            $pad = $depth > 0 ? ' style="margin-left:' . ($depth * 1) . 'em"' : '';
            $html .= '<li' . $pad . '><label>';
            $html .= '<input type="checkbox" name="post_category[]" value="' . $id . '"'
                . $checked . ' /> ';
            $html .= ap_esc_html((string) $term->name);
            $html .= '</label></li>';
            if (!empty($node['children']) && is_array($node['children'])) {
                /** @var list<array{term: object, children: list<array<string, mixed>>}> $children */
                $children = $node['children'];
                $html .= self::renderChecklistItems($children, $selectedIds, $depth + 1);
            }
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * Tags input (comma-separated) for post edit sidebar.
     *
     * @param list<object>|list<string> $tags
     */
    public static function renderTagsInput(array $tags = []): string
    {
        $names = [];
        foreach ($tags as $t) {
            if (is_object($t) && isset($t->name)) {
                $names[] = (string) $t->name;
            } elseif (is_string($t)) {
                $names[] = $t;
            }
        }
        $value = implode(', ', $names);

        $html = '<div class="ap-tags-input">';
        $html .= '<label class="screen-reader-text" for="tax-input-post_tag">Tags</label>';
        $html .= '<input type="text" name="tax_input[post_tag]" id="tax-input-post_tag" '
            . 'class="large-text" value="' . ap_esc_attr($value) . '" '
            . 'placeholder="Separate tags with commas" />';
        $html .= '<p class="description">Separate tags with commas.</p>';
        $html .= '</div>';

        return $html;
    }
}
