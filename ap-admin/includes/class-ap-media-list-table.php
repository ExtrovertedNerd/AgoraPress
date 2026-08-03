<?php

/**
 * Admin Media Library list / grid table.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Media library list renderer and bulk-action processor.
 */
class AP_Media_List_Table
{
    /** @var list<AP_Post> */
    public array $items = [];

    public int $totalItems = 0;

    public int $perPage = 40;

    public int $currentPage = 1;

    public int $totalPages = 1;

    public string $search = '';

    /** all|image|audio|video|application */
    public string $mimeFilter = 'all';

    public int $filterYear = 0;

    public int $filterMonth = 0;

    /** list|grid */
    public string $mode = 'grid';

    public string $orderby = 'date';

    public string $order = 'DESC';

    /** @var array<string, int> */
    public array $mimeCounts = [];

    private ?AP_DB $db;

    public function __construct(?AP_DB $db = null)
    {
        AP_Post::ensureBuiltins();
        $this->db = $db;
    }

    /**
     * @param array<string, mixed> $request
     */
    public function prepareItems(array $request = []): void
    {
        $this->search = trim((string) ($request['s'] ?? ''));
        $this->currentPage = max(1, (int) ($request['paged'] ?? 1));
        $this->perPage = max(1, min(100, (int) ($request['per_page'] ?? 40)));
        $this->filterYear = max(0, (int) ($request['m'] ?? $request['year'] ?? 0));
        // Support WP-style m=YYYYMM
        if ($this->filterYear >= 100000 && $this->filterYear <= 999999) {
            $ym = $this->filterYear;
            $this->filterYear = (int) floor($ym / 100);
            $this->filterMonth = $ym % 100;
        } else {
            $this->filterMonth = max(0, min(12, (int) ($request['month'] ?? 0)));
        }

        $mime = strtolower(trim((string) ($request['mime_type'] ?? $request['attachment-filter'] ?? 'all')));
        $allowedMime = ['all', 'image', 'audio', 'video', 'application'];
        $this->mimeFilter = in_array($mime, $allowedMime, true) ? $mime : 'all';

        $mode = strtolower(trim((string) ($request['mode'] ?? 'grid')));
        $this->mode = $mode === 'list' ? 'list' : 'grid';

        $orderby = strtolower((string) ($request['orderby'] ?? 'date'));
        $map = [
            'date' => 'post_date',
            'title' => 'post_title',
            'modified' => 'post_modified',
            'id' => 'ID',
            'mime' => 'post_mime_type',
        ];
        $this->orderby = $orderby;
        $dbOrderby = $map[$orderby] ?? 'post_date';
        $this->order = strtoupper((string) ($request['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $this->mimeCounts = AP_Media::mimeTypeCounts($this->resolveDb());

        $args = [
            's' => $this->search,
            'orderby' => $dbOrderby,
            'order' => $this->order,
            'limit' => $this->perPage,
            'offset' => ($this->currentPage - 1) * $this->perPage,
            'post_status' => 'inherit',
        ];
        if ($this->mimeFilter !== 'all') {
            $args['mime_type'] = $this->mimeFilter . '/*';
        }
        if ($this->filterYear > 0) {
            $args['year'] = $this->filterYear;
            if ($this->filterMonth > 0) {
                $args['month'] = $this->filterMonth;
            }
        }

        $result = AP_Media::query($args, $this->resolveDb());
        $this->items = $result['items'];
        $this->totalItems = $result['total'];
        $this->totalPages = max(1, (int) ceil($this->totalItems / $this->perPage));
        if ($this->currentPage > $this->totalPages) {
            $this->currentPage = $this->totalPages;
        }
    }

    /**
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
        if (!ap_check_nonce($nonce, 'bulk-media')) {
            return ['ok' => false, 'message_key' => 'nonce', 'count' => 0, 'errors' => ['Security check failed.']];
        }

        $ids = $post['media'] ?? $post['post'] ?? [];
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
            if ($action === 'delete') {
                $ok = AP_Media::deleteAttachment($id, $db);
            } else {
                $ok = false;
            }

            if ($ok) {
                $count++;
            } else {
                $errors[] = "Could not delete attachment #{$id}.";
            }
        }

        return [
            'ok' => $count > 0,
            'message_key' => $count > 0 ? 'bulk_deleted' : 'error',
            'count' => $count,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{ok: bool, message_key: string, errors: list<string>}
     */
    public function processRowAction(array $get): array
    {
        $action = (string) ($get['action'] ?? '');
        $id = (int) ($get['media'] ?? $get['post'] ?? 0);
        $nonce = (string) ($get['_ap_nonce'] ?? '');

        if ($action !== 'delete' || $id < 1) {
            return ['ok' => false, 'message_key' => '', 'errors' => ['Invalid action.']];
        }

        if (!ap_check_nonce($nonce, 'delete-media-' . $id)) {
            return ['ok' => false, 'message_key' => 'nonce', 'errors' => ['Security check failed.']];
        }

        $ok = AP_Media::deleteAttachment($id, $this->resolveDb());

        return [
            'ok' => $ok,
            'message_key' => $ok ? 'deleted' : 'error',
            'errors' => $ok ? [] : ['Could not delete that attachment.'],
        ];
    }

    /**
     * @return list<array{key: string, label: string, count: int, current: bool, url: string}>
     */
    public function getViews(): array
    {
        $defs = [
            'all' => 'All',
            'image' => 'Images',
            'audio' => 'Audio',
            'video' => 'Video',
            'application' => 'Documents',
        ];
        $views = [];
        foreach ($defs as $key => $label) {
            $count = (int) ($this->mimeCounts[$key] ?? 0);
            if ($key !== 'all' && $count < 1 && $this->mimeFilter !== $key) {
                continue;
            }
            $query = $this->baseQueryArgs();
            if ($key !== 'all') {
                $query['mime_type'] = $key;
            } else {
                unset($query['mime_type']);
            }
            $views[] = [
                'key' => $key,
                'label' => $label,
                'count' => $count,
                'current' => $this->mimeFilter === $key,
                'url' => AP_Admin::url('upload.php', $query),
            ];
        }

        return $views;
    }

    public function renderViews(): string
    {
        $parts = [];
        foreach ($this->getViews() as $view) {
            $class = $view['current'] ? ' class="current"' : '';
            $parts[] = '<li class="ap-view-' . ap_esc_attr($view['key']) . '">'
                . '<a href="' . ap_esc_url($view['url']) . '"' . $class . '>'
                . ap_esc_html($view['label'])
                . ' <span class="count">(' . (int) $view['count'] . ')</span>'
                . '</a></li>';
        }
        if ($parts === []) {
            return '';
        }

        return '<ul class="ap-subsubsub">' . implode(' | ', $parts) . '</ul>';
    }

    public function renderSearchBox(): string
    {
        $action = ap_esc_url(AP_Admin::url('upload.php'));
        $html = '<form class="ap-search-form" method="get" action="' . $action . '">';
        foreach ($this->baseQueryArgs(['s' => null]) as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $html .= '<input type="hidden" name="' . ap_esc_attr((string) $k) . '" value="'
                . ap_esc_attr((string) $v) . '" />';
        }
        $html .= '<label class="screen-reader-text" for="ap-media-search">Search Media</label>';
        $html .= '<input type="search" id="ap-media-search" name="s" value="'
            . ap_esc_attr($this->search) . '" placeholder="Search media…" />';
        $html .= '<button type="submit" class="button">Search</button>';
        $html .= '</form>';

        return $html;
    }

    public function renderModeToggle(): string
    {
        $gridQ = $this->baseQueryArgs(['mode' => 'grid']);
        $listQ = $this->baseQueryArgs(['mode' => 'list']);
        $gridClass = $this->mode === 'grid' ? ' button-primary' : '';
        $listClass = $this->mode === 'list' ? ' button-primary' : '';

        $gridHref = ap_esc_url(AP_Admin::url('upload.php', $gridQ));
        $listHref = ap_esc_url(AP_Admin::url('upload.php', $listQ));

        return '<div class="ap-view-switch" role="group" aria-label="View mode">'
            . '<a class="button' . $gridClass . '" href="' . $gridHref . '">Grid</a> '
            . '<a class="button' . $listClass . '" href="' . $listHref . '">List</a>'
            . '</div>';
    }

    public function renderDateFilter(): string
    {
        $db = $this->resolveDb();
        $table = $db->quoteIdentifier($db->table('posts'));
        $sql = 'SELECT DISTINCT strftime(\'%Y\', ' . $db->quoteIdentifier('post_date') . ') AS y'
            . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('post_type') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_status') . ' <> ?'
            . ' ORDER BY y DESC';

        // Driver-portable year extraction.
        $driver = method_exists($db, 'getDriver') ? (string) $db->getDriver() : 'sqlite';
        if ($driver === 'mysql') {
            $sql = 'SELECT DISTINCT YEAR(' . $db->quoteIdentifier('post_date') . ') AS y'
                . ' FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('post_type') . ' = ?'
                . ' AND ' . $db->quoteIdentifier('post_status') . ' <> ?'
                . ' ORDER BY y DESC';
        } elseif ($driver === 'pgsql') {
            $sql = 'SELECT DISTINCT EXTRACT(YEAR FROM ' . $db->quoteIdentifier('post_date') . '::timestamp) AS y'
                . ' FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('post_type') . ' = ?'
                . ' AND ' . $db->quoteIdentifier('post_status') . ' <> ?'
                . ' ORDER BY y DESC';
        }

        $years = [];
        try {
            $rows = $db->getResults($sql, ['attachment', 'trash']);
            foreach ($rows as $row) {
                $y = (int) ($row->y ?? 0);
                if ($y >= 1970) {
                    $years[] = $y;
                }
            }
        } catch (Throwable) {
            $years = [];
        }

        if ($years === []) {
            return '';
        }

        $action = ap_esc_url(AP_Admin::url('upload.php'));
        $html = '<form class="ap-media-date-filter" method="get" action="' . $action . '">';
        foreach ($this->baseQueryArgs(['year' => null, 'month' => null, 'm' => null]) as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $html .= '<input type="hidden" name="' . ap_esc_attr((string) $k) . '" value="'
                . ap_esc_attr((string) $v) . '" />';
        }
        $html .= '<label class="screen-reader-text" for="ap-media-year">Filter by year</label>';
        $html .= '<select id="ap-media-year" name="year">';
        $html .= '<option value="0">All dates</option>';
        foreach ($years as $y) {
            $sel = $this->filterYear === $y ? ' selected' : '';
            $html .= '<option value="' . $y . '"' . $sel . '>' . $y . '</option>';
        }
        $html .= '</select> ';
        $html .= '<label class="screen-reader-text" for="ap-media-month">Filter by month</label>';
        $html .= '<select id="ap-media-month" name="month">';
        $html .= '<option value="0">All months</option>';
        for ($m = 1; $m <= 12; $m++) {
            $sel = $this->filterMonth === $m ? ' selected' : '';
            $label = date('F', mktime(0, 0, 0, $m, 1));
            $html .= '<option value="' . $m . '"' . $sel . '>' . ap_esc_html($label) . '</option>';
        }
        $html .= '</select> ';
        $html .= '<button type="submit" class="button">Filter</button>';
        $html .= '</form>';

        return $html;
    }

    public function render(): string
    {
        if ($this->mode === 'grid') {
            return $this->renderGrid();
        }

        return $this->renderList();
    }

    private function renderList(): string
    {
        $actionUrl = ap_esc_url(AP_Admin::url('upload.php', $this->baseQueryArgs()));
        $html = '<form method="post" action="' . $actionUrl . '" class="ap-list-table-form">';
        $html .= ap_nonce_field('bulk-media', '_ap_nonce', false);
        $html .= '<div class="ap-tablenav ap-tablenav-top">';
        $html .= $this->renderBulkDropdown('action');
        $html .= '<button type="submit" class="button">Apply</button>';
        $html .= $this->renderPagination();
        $html .= '</div>';

        $html .= '<table class="ap-list-table widefat striped ap-media-list">';
        $html .= '<thead><tr>';
        $html .= '<th scope="col" class="check-column">'
            . '<input type="checkbox" id="cb-select-all" data-target="media[]" aria-label="Select all" />'
            . '</th>';
        $html .= '<th scope="col">File</th>'
            . '<th scope="col">Title</th>'
            . '<th scope="col">Type</th>'
            . '<th scope="col">Date</th>'
            . '</tr></thead><tbody>';

        if ($this->items === []) {
            $html .= '<tr class="no-items"><td colspan="5">No media items found.</td></tr>';
        } else {
            foreach ($this->items as $post) {
                $html .= $this->renderListRow($post);
            }
        }

        $html .= '</tbody></table>';
        $html .= '<div class="ap-tablenav ap-tablenav-bottom">';
        $html .= $this->renderBulkDropdown('action2');
        $html .= '<button type="submit" class="button">Apply</button>';
        $html .= $this->renderPagination();
        $html .= '</div></form>';

        return $html;
    }

    private function renderGrid(): string
    {
        $actionUrl = ap_esc_url(AP_Admin::url('upload.php', $this->baseQueryArgs()));
        $html = '<form method="post" action="' . $actionUrl . '" class="ap-media-grid-form">';
        $html .= ap_nonce_field('bulk-media', '_ap_nonce', false);
        $html .= '<div class="ap-tablenav ap-tablenav-top">';
        $html .= $this->renderBulkDropdown('action');
        $html .= '<button type="submit" class="button">Apply</button>';
        $html .= $this->renderPagination();
        $html .= '</div>';

        if ($this->items === []) {
            $html .= '<p class="ap-media-empty">No media items found. Upload files to get started.</p>';
        } else {
            $html .= '<ul class="ap-media-grid">';
            foreach ($this->items as $post) {
                $html .= $this->renderGridItem($post);
            }
            $html .= '</ul>';
        }

        $html .= '<div class="ap-tablenav ap-tablenav-bottom">';
        $html .= $this->renderBulkDropdown('action2');
        $html .= '<button type="submit" class="button">Apply</button>';
        $html .= $this->renderPagination();
        $html .= '</div></form>';

        return $html;
    }

    private function renderListRow(AP_Post $post): string
    {
        $id = (int) $post->ID;
        $editUrl = ap_esc_url(AP_Admin::url('media.php', ['item' => $id]));
        $title = $post->post_title !== '' ? $post->post_title : '(no title)';
        $url = AP_Media::getAttachmentUrl($id, $this->resolveDb());
        $thumb = $this->thumbnailHtml($post, 48);
        $date = $post->post_date !== '' ? substr($post->post_date, 0, 16) : '—';

        $row = '<tr id="media-' . $id . '">';
        $row .= '<th scope="row" class="check-column">'
            . '<input type="checkbox" name="media[]" value="' . $id . '" />'
            . '</th>';
        $row .= '<td class="column-icon"><a href="' . $editUrl . '">' . $thumb . '</a></td>';
        $row .= '<td class="column-title"><strong><a class="row-title" href="' . $editUrl . '">'
            . ap_esc_html($title) . '</a></strong>';
        $row .= $this->renderRowActions($post);
        if ($url !== '') {
            $row .= '<div class="ap-media-url"><code>' . ap_esc_html($url) . '</code></div>';
        }
        $row .= '</td>';
        $row .= '<td class="column-mime">' . ap_esc_html($post->post_mime_type) . '</td>';
        $row .= '<td class="column-date">' . ap_esc_html($date) . '</td>';
        $row .= '</tr>';

        return $row;
    }

    private function renderGridItem(AP_Post $post): string
    {
        $id = (int) $post->ID;
        $editUrl = ap_esc_url(AP_Admin::url('media.php', ['item' => $id]));
        $title = $post->post_title !== '' ? $post->post_title : '(no title)';
        $thumb = $this->thumbnailHtml($post, 150);

        return '<li class="ap-media-grid-item" id="media-' . $id . '">'
            . '<label class="ap-media-grid-check">'
            . '<input type="checkbox" name="media[]" value="' . $id . '" />'
            . '<span class="screen-reader-text">Select ' . ap_esc_html($title) . '</span>'
            . '</label>'
            . '<a class="ap-media-grid-thumb" href="' . $editUrl . '" title="' . ap_esc_attr($title) . '">'
            . $thumb
            . '</a>'
            . '<div class="ap-media-grid-title"><a href="' . $editUrl . '">'
            . ap_esc_html($title) . '</a></div>'
            . '</li>';
    }

    private function thumbnailHtml(AP_Post $post, int $size): string
    {
        $id = (int) $post->ID;
        $url = AP_Media::getAttachmentUrl($id, $this->resolveDb());
        $title = $post->post_title !== '' ? $post->post_title : 'Attachment';
        $mime = $post->post_mime_type;

        if (AP_Media::isImage($post) && $url !== '') {
            $alt = AP_Media::getAltText($id, $this->resolveDb()) ?: $title;

            return '<img src="' . ap_esc_url($url) . '" alt="' . ap_esc_attr($alt) . '"'
                . ' width="' . $size . '" height="' . $size . '"'
                . ' loading="lazy" class="ap-media-thumb" />';
        }

        $rel = (string) (AP_Media::getAttachedFileRelative($id, $this->resolveDb()) ?: 'FILE');
        $ext = strtoupper(pathinfo($rel, PATHINFO_EXTENSION) ?: 'FILE');
        $label = match (true) {
            str_starts_with($mime, 'audio/') => 'Audio',
            str_starts_with($mime, 'video/') => 'Video',
            str_contains($mime, 'pdf') => 'PDF',
            str_contains($mime, 'zip') => 'ZIP',
            default => $ext,
        };

        return '<span class="ap-media-icon" aria-hidden="true">'
            . ap_esc_html($label) . '</span>';
    }

    private function renderRowActions(AP_Post $post): string
    {
        $id = (int) $post->ID;
        $editUrl = AP_Admin::url('media.php', ['item' => $id]);
        $nonce = ap_create_nonce('delete-media-' . $id);
        $deleteUrl = AP_Admin::url('upload.php', [
            'action' => 'delete',
            'media' => $id,
            '_ap_nonce' => $nonce,
            'mode' => $this->mode,
        ]);
        $fileUrl = AP_Media::getAttachmentUrl($id, $this->resolveDb());

        $parts = [
            '<span class="edit"><a href="' . ap_esc_url($editUrl) . '">Edit</a></span>',
        ];
        if ($fileUrl !== '') {
            $parts[] = '<span class="view"><a href="' . ap_esc_url($fileUrl)
                . '" target="_blank" rel="noopener">View</a></span>';
        }
        $confirm = 'return confirm(\'Delete this file permanently?\');';
        $parts[] = '<span class="delete"><a class="ap-delete-link" href="'
            . ap_esc_url($deleteUrl) . '" onclick="' . $confirm . '">Delete</a></span>';

        return '<div class="row-actions">' . implode(' | ', $parts) . '</div>';
    }

    /**
     * @param array<string, string> $actions
     */
    private function renderBulkDropdown(string $name): string
    {
        $html = '<label class="screen-reader-text" for="' . ap_esc_attr($name) . '">Select bulk action</label>';
        $html .= '<select name="' . ap_esc_attr($name) . '" id="' . ap_esc_attr($name) . '">';
        $html .= '<option value="-1">Bulk actions</option>';
        $html .= '<option value="delete">Delete permanently</option>';
        $html .= '</select> ';

        return $html;
    }

    private function renderPagination(): string
    {
        if ($this->totalPages <= 1) {
            return '<div class="ap-pagination"><span class="ap-displaying-num">'
                . (int) $this->totalItems . ' item'
                . ($this->totalItems === 1 ? '' : 's')
                . '</span></div>';
        }

        $html = '<div class="ap-pagination">';
        $html .= '<span class="ap-displaying-num">' . (int) $this->totalItems . ' items</span> ';
        if ($this->currentPage > 1) {
            $prev = $this->baseQueryArgs(['paged' => $this->currentPage - 1]);
            $html .= '<a class="button" href="' . ap_esc_url(AP_Admin::url('upload.php', $prev)) . '">‹ Prev</a> ';
        }
        $html .= '<span class="ap-paging-text">Page '
            . (int) $this->currentPage . ' of ' . (int) $this->totalPages . '</span> ';
        if ($this->currentPage < $this->totalPages) {
            $next = $this->baseQueryArgs(['paged' => $this->currentPage + 1]);
            $html .= '<a class="button" href="' . ap_esc_url(AP_Admin::url('upload.php', $next)) . '">Next ›</a>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function baseQueryArgs(array $overrides = []): array
    {
        $args = [
            'mode' => $this->mode,
        ];
        if ($this->mimeFilter !== 'all') {
            $args['mime_type'] = $this->mimeFilter;
        }
        if ($this->search !== '') {
            $args['s'] = $this->search;
        }
        if ($this->filterYear > 0) {
            $args['year'] = $this->filterYear;
        }
        if ($this->filterMonth > 0) {
            $args['month'] = $this->filterMonth;
        }
        if ($this->currentPage > 1) {
            $args['paged'] = $this->currentPage;
        }

        foreach ($overrides as $k => $v) {
            if ($v === null) {
                unset($args[$k]);
            } else {
                $args[$k] = $v;
            }
        }

        return $args;
    }

    private function resolveDb(): AP_DB
    {
        if ($this->db instanceof AP_DB) {
            return $this->db;
        }

        return ap_db();
    }
}
