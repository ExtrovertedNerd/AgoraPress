<?php

/**
 * Admin list table for posts and pages.
 *
 * Columns, status views, search, pagination, and bulk trash/untrash/delete.
 * Categories/tags columns for post type when those taxonomies apply.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Posts / pages list table renderer and bulk-action processor.
 */
class AP_Posts_List_Table
{
    public string $postType = 'post';

    /** @var list<AP_Post> */
    public array $items = [];

    public int $totalItems = 0;

    public int $perPage = 20;

    public int $currentPage = 1;

    public int $totalPages = 1;

    /** Active status view: all|publish|draft|pending|private|future|trash */
    public string $statusView = 'all';

    public string $search = '';

    /** Active category term_id filter (posts only); 0 = all categories. */
    public int $catId = 0;

    public string $orderby = 'date';

    public string $order = 'DESC';

    /** @var array<string, int> status => count */
    public array $statusCounts = [];

    private ?AP_DB $db;

    public function __construct(string $postType = 'post', ?AP_DB $db = null)
    {
        AP_Post::ensureBuiltins();
        $this->postType = AP_Admin::resolvePostType($postType, 'post');
        $this->db = $db;
    }

    /**
     * Read list state from a request bag (typically $_GET) and load items.
     *
     * @param array<string, mixed> $request
     */
    public function prepareItems(array $request = []): void
    {
        $this->statusView = $this->normalizeStatusView((string) ($request['post_status'] ?? 'all'));
        $this->search = trim((string) ($request['s'] ?? ''));
        $this->currentPage = max(1, (int) ($request['paged'] ?? 1));
        $this->perPage = max(1, min(100, (int) ($request['per_page'] ?? 20)));
        $this->catId = $this->postType === 'post'
            ? max(0, (int) ($request['cat'] ?? 0))
            : 0;

        $orderby = strtolower((string) ($request['orderby'] ?? 'date'));
        $allowedOrderby = ['date', 'title', 'modified', 'menu_order', 'id'];
        $this->orderby = in_array($orderby, $allowedOrderby, true) ? $orderby : 'date';
        $this->order = strtoupper((string) ($request['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        // Hierarchical pages default to menu_order ASC when not searching / not trash.
        if (
            $this->postType === 'page'
            && $this->statusView !== 'trash'
            && $this->search === ''
            && !isset($request['orderby'])
        ) {
            $this->orderby = 'menu_order';
            $this->order = 'ASC';
        }

        $this->statusCounts = $this->countByStatus();
        $this->loadItems();
    }

    /**
     * Process bulk actions from a POST body.
     *
     * @param array<string, mixed> $post
     *
     * @return array{ok: bool, message_key: string, count: int, errors: list<string>}
     */
    public function processBulkAction(array $post): array
    {
        $action = (string) ($post['action'] ?? $post['action2'] ?? '-1');
        if ($action === '' || $action === '-1') {
            return ['ok' => false, 'message_key' => '', 'count' => 0, 'errors' => ['No bulk action selected.']];
        }

        $nonce = (string) ($post['_ap_nonce'] ?? '');
        if (!ap_check_nonce($nonce, 'bulk-posts')) {
            return ['ok' => false, 'message_key' => 'nonce', 'count' => 0, 'errors' => ['Security check failed.']];
        }

        $ids = $post['post'] ?? $post['post_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return ['ok' => false, 'message_key' => '', 'count' => 0, 'errors' => ['No items selected.']];
        }

        $db = $this->resolveDb();
        $count = 0;
        $errors = [];

        foreach ($ids as $id) {
            $item = AP_Post::get($id, $db);
            if ($item === null || $item->post_type !== $this->postType) {
                $errors[] = "Item #{$id} not found.";
                continue;
            }

            $ok = match ($action) {
                'trash' => AP_Post::trash($id, $db),
                'untrash' => AP_Post::untrash($id, $db),
                'delete' => AP_Post::delete($id, true, $db),
                'draft' => AP_Post::update($id, ['post_status' => 'draft'], $db),
                'publish' => AP_Post::update($id, ['post_status' => 'publish'], $db),
                'pending' => AP_Post::update($id, ['post_status' => 'pending'], $db),
                'private' => AP_Post::update($id, ['post_status' => 'private'], $db),
                default => false,
            };

            if ($ok) {
                $count++;
            } else {
                $errors[] = "Could not apply “{$action}” to #{$id}.";
            }
        }

        $messageKey = match ($action) {
            'trash' => 'bulk_trashed',
            'untrash' => 'bulk_untrashed',
            'delete' => 'bulk_deleted',
            default => $count > 0 ? 'updated' : 'error',
        };

        return [
            'ok' => $count > 0,
            'message_key' => $messageKey,
            'count' => $count,
            'errors' => $errors,
        ];
    }

    /**
     * Columns for the table.
     *
     * @return array<string, string> key => label
     */
    public function getColumns(): array
    {
        $cols = [
            'cb' => '',
            'title' => 'Title',
            'author' => 'Author',
        ];
        if ($this->postType === 'post') {
            if (class_exists('AP_Taxonomy', false)) {
                if (in_array('category', AP_Taxonomy::getObjectTaxonomies('post'), true)) {
                    $cols['categories'] = 'Categories';
                }
                if (in_array('post_tag', AP_Taxonomy::getObjectTaxonomies('post'), true)) {
                    $cols['tags'] = 'Tags';
                }
            }
            $cols['comments'] = 'Comments';
        }
        if ($this->postType === 'page') {
            $cols['order'] = 'Order';
        }
        $cols['date'] = 'Date';
        $cols['status'] = 'Status';

        return $cols;
    }

    /**
     * Bulk action options for the current status view.
     *
     * @return array<string, string>
     */
    public function getBulkActions(): array
    {
        if ($this->statusView === 'trash') {
            return [
                'untrash' => 'Restore',
                'delete' => 'Delete Permanently',
            ];
        }

        return [
            'trash' => 'Move to Trash',
            'publish' => 'Change status to Published',
            'draft' => 'Change status to Draft',
            'pending' => 'Change status to Pending',
            'private' => 'Change status to Private',
        ];
    }

    /**
     * Status view links data.
     *
     * @return list<array{key: string, label: string, count: int, current: bool, url: string}>
     */
    public function getViews(): array
    {
        $views = [];
        $allCount = 0;
        foreach ($this->statusCounts as $status => $count) {
            if ($status === 'trash' || $status === 'auto-draft' || $status === 'inherit') {
                continue;
            }
            $obj = AP_Post::getStatusObject($status);
            if ($obj !== null && empty($obj['show_in_admin_all_list'])) {
                continue;
            }
            $allCount += $count;
        }

        $defs = [
            'all' => ['label' => 'All', 'count' => $allCount],
            'publish' => ['label' => 'Published', 'count' => $this->statusCounts['publish'] ?? 0],
            'draft' => ['label' => 'Draft', 'count' => $this->statusCounts['draft'] ?? 0],
            'pending' => ['label' => 'Pending', 'count' => $this->statusCounts['pending'] ?? 0],
            'private' => ['label' => 'Private', 'count' => $this->statusCounts['private'] ?? 0],
            'future' => ['label' => 'Scheduled', 'count' => $this->statusCounts['future'] ?? 0],
            'trash' => ['label' => 'Trash', 'count' => $this->statusCounts['trash'] ?? 0],
        ];

        foreach ($defs as $key => $meta) {
            // Hide empty views except All and Trash (always show when trash has items or current).
            if ($key !== 'all' && $key !== 'trash' && $meta['count'] < 1 && $this->statusView !== $key) {
                continue;
            }
            if ($key === 'trash' && $meta['count'] < 1 && $this->statusView !== 'trash') {
                continue;
            }
            $query = ['post_type' => $this->postType];
            if ($key !== 'all') {
                $query['post_status'] = $key;
            }
            if ($this->search !== '') {
                $query['s'] = $this->search;
            }
            if ($this->catId > 0) {
                $query['cat'] = $this->catId;
            }
            $views[] = [
                'key' => $key,
                'label' => $meta['label'],
                'count' => $meta['count'],
                'current' => $this->statusView === $key,
                'url' => AP_Admin::url('edit.php', $query),
            ];
        }

        return $views;
    }

    /**
     * Render status view links HTML.
     */
    public function renderViews(): string
    {
        $parts = [];
        foreach ($this->getViews() as $view) {
            $class = $view['current'] ? ' class="current"' : '';
            $label = ap_esc_html($view['label']);
            $count = (int) $view['count'];
            $url = ap_esc_url($view['url']);
            $parts[] = '<li class="ap-view-' . ap_esc_attr($view['key']) . '">'
                . '<a href="' . $url . '"' . $class . '>'
                . $label . ' <span class="count">(' . $count . ')</span>'
                . '</a></li>';
        }

        if ($parts === []) {
            return '';
        }

        return '<ul class="ap-subsubsub">' . implode(' | ', $parts) . '</ul>';
    }

    /**
     * Render the search box.
     */
    public function renderSearchBox(): string
    {
        $s = ap_esc_attr($this->search);
        $type = ap_esc_attr($this->postType);
        $status = $this->statusView !== 'all' ? ap_esc_attr($this->statusView) : '';

        $html = '<form class="ap-search-form" method="get" action="' . ap_esc_url(AP_Admin::url('edit.php')) . '">';
        $html .= '<input type="hidden" name="post_type" value="' . $type . '" />';
        if ($status !== '') {
            $html .= '<input type="hidden" name="post_status" value="' . $status . '" />';
        }
        if ($this->catId > 0) {
            $html .= '<input type="hidden" name="cat" value="' . (int) $this->catId . '" />';
        }
        $html .= '<label class="screen-reader-text" for="ap-post-search-input">Search</label>';
        $html .= '<input type="search" id="ap-post-search-input" name="s" value="' . $s . '" placeholder="Search…" />';
        $html .= '<button type="submit" class="button">Search</button>';
        $html .= '</form>';

        return $html;
    }

    /**
     * Category dropdown filter for the posts list (GET form).
     */
    public function renderCategoryFilter(): string
    {
        if (
            $this->postType !== 'post'
            || !class_exists('AP_Taxonomy', false)
            || !in_array('category', AP_Taxonomy::getObjectTaxonomies('post'), true)
        ) {
            return '';
        }

        $db = $this->resolveDb();
        AP_Taxonomy::ensureDefaultCategory($db);
        $terms = AP_Taxonomy::getTerms('category', [
            'hide_empty' => false,
            'orderby' => 'name',
            'fields' => 'all',
        ], $db);

        $type = ap_esc_attr($this->postType);
        $status = $this->statusView !== 'all' ? ap_esc_attr($this->statusView) : '';
        $s = ap_esc_attr($this->search);

        $html = '<form class="ap-category-filter-form" method="get" action="'
            . ap_esc_url(AP_Admin::url('edit.php')) . '">';
        $html .= '<input type="hidden" name="post_type" value="' . $type . '" />';
        if ($status !== '') {
            $html .= '<input type="hidden" name="post_status" value="' . $status . '" />';
        }
        if ($this->search !== '') {
            $html .= '<input type="hidden" name="s" value="' . $s . '" />';
        }
        $html .= '<label class="screen-reader-text" for="ap-cat-filter">Filter by category</label>';
        $html .= '<select name="cat" id="ap-cat-filter">';
        $html .= '<option value="0"' . ($this->catId === 0 ? ' selected' : '') . '>All Categories</option>';
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if (!is_object($term)) {
                    continue;
                }
                $id = (int) $term->term_id;
                $selected = $id === $this->catId ? ' selected' : '';
                $html .= '<option value="' . $id . '"' . $selected . '>'
                    . ap_esc_html((string) $term->name) . '</option>';
            }
        }
        $html .= '</select> ';
        $html .= '<button type="submit" class="button">Filter</button>';
        $html .= '</form>';

        return $html;
    }

    /**
     * Full list table HTML (form + table + bulk + pagination).
     */
    public function render(): string
    {
        $type = ap_esc_attr($this->postType);
        $actionUrl = ap_esc_url(AP_Admin::url('edit.php', ['post_type' => $this->postType]));
        $bulk = $this->getBulkActions();
        $columns = $this->getColumns();

        $html = '';
        $catFilter = $this->renderCategoryFilter();
        if ($catFilter !== '') {
            $html .= '<div class="ap-tablenav ap-tablenav-filters">' . $catFilter . '</div>';
        }

        $html .= '<form method="post" action="' . $actionUrl . '" class="ap-list-table-form">';
        $html .= ap_nonce_field('bulk-posts', '_ap_nonce', false);
        $html .= '<input type="hidden" name="post_type" value="' . $type . '" />';
        if ($this->statusView !== 'all') {
            $html .= '<input type="hidden" name="post_status" value="' . ap_esc_attr($this->statusView) . '" />';
        }

        $html .= '<div class="ap-tablenav ap-tablenav-top">';
        $html .= $this->renderBulkDropdown('action', $bulk);
        $html .= '<button type="submit" class="button">Apply</button>';
        $html .= $this->renderPagination();
        $html .= '</div>';

        $html .= '<table class="ap-list-table widefat striped">';
        $html .= '<thead><tr>';
        foreach ($columns as $key => $label) {
            if ($key === 'cb') {
                $html .= '<th scope="col" class="check-column">'
                    . '<input type="checkbox" id="cb-select-all" aria-label="Select all" />'
                    . '</th>';
            } else {
                $html .= '<th scope="col" class="column-' . ap_esc_attr($key) . '">'
                    . ap_esc_html($label) . '</th>';
            }
        }
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        if ($this->items === []) {
            $colspan = count($columns);
            $html .= '<tr class="no-items"><td colspan="' . $colspan . '">No items found.</td></tr>';
        } else {
            foreach ($this->items as $post) {
                $html .= $this->renderRow($post, $columns);
            }
        }
        $html .= '</tbody>';

        $html .= '<tfoot><tr>';
        foreach ($columns as $key => $label) {
            if ($key === 'cb') {
                $html .= '<th scope="col" class="check-column"></th>';
            } else {
                $html .= '<th scope="col">' . ap_esc_html($label) . '</th>';
            }
        }
        $html .= '</tr></tfoot>';
        $html .= '</table>';

        $html .= '<div class="ap-tablenav ap-tablenav-bottom">';
        $html .= $this->renderBulkDropdown('action2', $bulk);
        $html .= '<button type="submit" class="button">Apply</button>';
        $html .= $this->renderPagination();
        $html .= '</div>';

        $html .= '</form>';

        return $html;
    }

    /**
     * @param array<string, string> $columns
     */
    private function renderRow(AP_Post $post, array $columns): string
    {
        $id = (int) $post->ID;
        $editUrl = ap_esc_url(AP_Admin::url('post.php', [
            'post' => $id,
            'action' => 'edit',
        ]));
        $title = $post->post_title !== '' ? $post->post_title : '(no title)';
        $indent = '';
        if ($this->postType === 'page' && $post->post_parent > 0 && $this->statusView !== 'trash') {
            $depth = count(AP_Post::getAncestorIds($id, $this->resolveDb()));
            if ($depth > 0) {
                $indent = '<span class="ap-page-indent" style="padding-left:' . ($depth * 1.25) . 'em"></span>';
            }
        }

        $row = '<tr id="post-' . $id . '" class="status-' . ap_esc_attr($post->post_status) . '">';
        foreach ($columns as $key => $label) {
            switch ($key) {
                case 'cb':
                    $row .= '<th scope="row" class="check-column">'
                        . '<input type="checkbox" name="post[]" value="' . $id . '" />'
                        . '</th>';
                    break;
                case 'title':
                    $row .= '<td class="column-title" data-colname="Title">';
                    $row .= $indent . '<strong><a class="row-title" href="' . $editUrl . '">'
                        . ap_esc_html($title) . '</a></strong>';
                    $row .= $this->renderRowActions($post);
                    $row .= '</td>';
                    break;
                case 'author':
                    $row .= '<td class="column-author" data-colname="Author">'
                        . ap_esc_html($this->authorLabel($post->post_author)) . '</td>';
                    break;
                case 'categories':
                    $row .= '<td class="column-categories" data-colname="Categories">'
                        . $this->termNamesCell($post->ID, 'category') . '</td>';
                    break;
                case 'tags':
                    $row .= '<td class="column-tags" data-colname="Tags">'
                        . $this->termNamesCell($post->ID, 'post_tag') . '</td>';
                    break;
                case 'comments':
                    $row .= '<td class="column-comments" data-colname="Comments">'
                        . (int) $post->comment_count . '</td>';
                    break;
                case 'order':
                    $row .= '<td class="column-order" data-colname="Order">'
                        . (int) $post->menu_order . '</td>';
                    break;
                case 'date':
                    $row .= '<td class="column-date" data-colname="Date">'
                        . $this->formatDate($post) . '</td>';
                    break;
                case 'status':
                    $row .= '<td class="column-status" data-colname="Status">'
                        . ap_esc_html($this->statusLabel($post->post_status)) . '</td>';
                    break;
                default:
                    $row .= '<td></td>';
            }
        }
        $row .= '</tr>';

        return $row;
    }

    private function renderRowActions(AP_Post $post): string
    {
        $id = (int) $post->ID;
        $editUrl = AP_Admin::url('post.php', ['post' => $id, 'action' => 'edit']);
        $actions = [];

        if ($post->post_status === 'trash') {
            $actions['untrash'] = '<a href="' . ap_esc_url(AP_Admin::url('edit.php', [
                'post_type' => $this->postType,
                'action' => 'untrash',
                'post' => $id,
                '_ap_nonce' => ap_create_nonce('post-row-' . $id),
            ])) . '">Restore</a>';
            $actions['delete'] = '<a class="submitdelete" href="' . ap_esc_url(AP_Admin::url('edit.php', [
                'post_type' => $this->postType,
                'action' => 'delete',
                'post' => $id,
                '_ap_nonce' => ap_create_nonce('post-row-' . $id),
            ])) . '">Delete Permanently</a>';
        } else {
            $actions['edit'] = '<a href="' . ap_esc_url($editUrl) . '">Edit</a>';
            $actions['trash'] = '<a class="submitdelete" href="' . ap_esc_url(AP_Admin::url('edit.php', [
                'post_type' => $this->postType,
                'action' => 'trash',
                'post' => $id,
                '_ap_nonce' => ap_create_nonce('post-row-' . $id),
            ])) . '">Trash</a>';
        }

        $parts = [];
        foreach ($actions as $key => $link) {
            $parts[] = '<span class="' . ap_esc_attr($key) . '">' . $link . '</span>';
        }

        return '<div class="row-actions">' . implode(' | ', $parts) . '</div>';
    }

    /**
     * @param array<string, string> $actions
     */
    private function renderBulkDropdown(string $name, array $actions): string
    {
        $html = '<label class="screen-reader-text" for="' . ap_esc_attr($name) . '">Select bulk action</label>';
        $html .= '<select name="' . ap_esc_attr($name) . '" id="' . ap_esc_attr($name) . '">';
        $html .= '<option value="-1">Bulk actions</option>';
        foreach ($actions as $value => $label) {
            $html .= '<option value="' . ap_esc_attr($value) . '">' . ap_esc_html($label) . '</option>';
        }
        $html .= '</select> ';

        return $html;
    }

    private function renderPagination(): string
    {
        if ($this->totalPages <= 1) {
            return '<div class="ap-pagination"><span class="displaying-num">'
                . (int) $this->totalItems . ' item'
                . ($this->totalItems === 1 ? '' : 's')
                . '</span></div>';
        }

        $baseQuery = ['post_type' => $this->postType];
        if ($this->statusView !== 'all') {
            $baseQuery['post_status'] = $this->statusView;
        }
        if ($this->search !== '') {
            $baseQuery['s'] = $this->search;
        }
        if ($this->catId > 0) {
            $baseQuery['cat'] = $this->catId;
        }

        $html = '<div class="ap-pagination">';
        $html .= '<span class="displaying-num">' . (int) $this->totalItems . ' items</span> ';
        $html .= '<span class="pagination-links">';

        if ($this->currentPage > 1) {
            $prev = $baseQuery;
            $prev['paged'] = $this->currentPage - 1;
            $html .= '<a class="prev-page button" href="' . ap_esc_url(AP_Admin::url('edit.php', $prev)) . '">‹</a> ';
        } else {
            $html .= '<span class="tablenav-pages-navspan button disabled">‹</span> ';
        }

        $html .= '<span class="paging-input">'
            . (int) $this->currentPage . ' of <span class="total-pages">'
            . (int) $this->totalPages . '</span></span> ';

        if ($this->currentPage < $this->totalPages) {
            $next = $baseQuery;
            $next['paged'] = $this->currentPage + 1;
            $html .= '<a class="next-page button" href="' . ap_esc_url(AP_Admin::url('edit.php', $next)) . '">›</a>';
        } else {
            $html .= '<span class="tablenav-pages-navspan button disabled">›</span>';
        }

        $html .= '</span></div>';

        return $html;
    }

    /**
     * Comma-separated term names for a post column.
     */
    private function termNamesCell(int $postId, string $taxonomy): string
    {
        if (!class_exists('AP_Taxonomy', false) || $postId < 1) {
            return '—';
        }
        $names = AP_Taxonomy::getObjectTerms($postId, $taxonomy, ['fields' => 'names'], $this->resolveDb());
        if ($names === []) {
            return '—';
        }
        /** @var list<string> $names */
        $escaped = array_map(static fn (string $n): string => ap_esc_html($n), $names);

        return implode(', ', $escaped);
    }

    private function loadItems(): void
    {
        $db = $this->resolveDb();
        $statusArg = $this->statusForQuery();

        $orderbyMap = [
            'date' => 'date',
            'title' => 'title',
            'modified' => 'modified',
            'menu_order' => 'menu_order',
            'id' => 'ID',
        ];

        $queryArgs = [
            'post_type' => $this->postType,
            'post_status' => $statusArg,
            's' => $this->search,
            'posts_per_page' => $this->perPage,
            'paged' => $this->currentPage,
            'orderby' => $orderbyMap[$this->orderby] ?? 'date',
            'order' => $this->order,
        ];
        if ($this->catId > 0 && class_exists('AP_Taxonomy', false)) {
            $queryArgs['cat'] = $this->catId;
        }
        $q = new AP_Query($queryArgs, $db);

        $this->items = [];
        foreach ($q->posts as $item) {
            if ($item instanceof AP_Post) {
                $this->items[] = $item;
            }
        }
        $this->totalItems = $q->found_posts > 0 ? $q->found_posts : count($this->items);
        // When nopaging edge: still show count.
        if ($q->found_posts === 0 && $this->items !== [] && $q->query_vars['no_found_rows']) {
            $this->totalItems = count($this->items);
        }
        $this->totalPages = max(1, (int) ceil($this->totalItems / $this->perPage));
        if ($q->max_num_pages > 0) {
            $this->totalPages = $q->max_num_pages;
        }
    }

    /**
     * Status filter for AP_Query.
     *
     * @return string|list<string>
     */
    private function statusForQuery(): string|array
    {
        if ($this->statusView === 'all') {
            $statuses = [];
            foreach (AP_Post::getStatuses() as $name => $obj) {
                if (!empty($obj['show_in_admin_all_list'])) {
                    $statuses[] = $name;
                }
            }

            return $statuses !== [] ? $statuses : 'any';
        }

        return $this->statusView;
    }

    /**
     * @return array<string, int>
     */
    private function countByStatus(): array
    {
        $db = $this->resolveDb();
        $table = $db->quoteIdentifier($db->table('posts'));
        $sql = 'SELECT ' . $db->quoteIdentifier('post_status') . ' AS st, COUNT(*) AS cnt FROM '
            . $table . ' WHERE ' . $db->quoteIdentifier('post_type') . ' = ? GROUP BY '
            . $db->quoteIdentifier('post_status');
        $rows = $db->getResults($sql, [$this->postType]);
        $counts = [];
        foreach ($rows as $row) {
            $data = is_array($row) ? $row : get_object_vars($row);
            $st = (string) ($data['st'] ?? '');
            $counts[$st] = (int) ($data['cnt'] ?? 0);
        }

        return $counts;
    }

    private function normalizeStatusView(string $raw): string
    {
        $raw = strtolower(trim($raw));
        if ($raw === '' || $raw === 'all') {
            return 'all';
        }
        if (AP_Post::statusExists($raw)) {
            return $raw;
        }

        return 'all';
    }

    private function authorLabel(int $authorId): string
    {
        if ($authorId < 1) {
            return '—';
        }
        if (class_exists('AP_User', false)) {
            $user = AP_User::getBy('id', $authorId, $this->resolveDb());
            if ($user !== null) {
                return $user->display_name !== '' ? $user->display_name : $user->user_login;
            }
        }

        return '#' . $authorId;
    }

    private function statusLabel(string $status): string
    {
        $obj = AP_Post::getStatusObject($status);

        return $obj !== null ? (string) ($obj['label'] ?? $status) : $status;
    }

    private function formatDate(AP_Post $post): string
    {
        $date = $post->post_date !== '' ? $post->post_date : $post->post_modified;
        if ($date === '') {
            return '—';
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return ap_esc_html($date);
        }
        $prefix = match ($post->post_status) {
            'publish' => 'Published',
            'future' => 'Scheduled',
            'draft', 'auto-draft' => 'Last Modified',
            default => 'Date',
        };

        return ap_esc_html($prefix) . '<br /><span class="ap-date">'
            . ap_esc_html(date('Y/m/d g:i a', $ts)) . '</span>';
    }

    private function resolveDb(): AP_DB
    {
        if ($this->db instanceof AP_DB) {
            return $this->db;
        }

        return ap_db();
    }
}
