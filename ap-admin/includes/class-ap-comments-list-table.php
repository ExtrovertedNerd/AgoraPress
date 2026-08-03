<?php

/**
 * Admin list table for comments (moderation queue).
 *
 * Columns, status views (All / Pending / Approved / Spam / Trash), search,
 * pagination, and bulk approve/unapprove/spam/trash/delete.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Comments list table renderer and bulk-action processor.
 */
class AP_Comments_List_Table
{
    /** @var list<AP_Comment> */
    public array $items = [];

    public int $totalItems = 0;

    public int $perPage = 20;

    public int $currentPage = 1;

    public int $totalPages = 1;

    /** Active status view: all|moderated|approved|spam|trash */
    public string $statusView = 'all';

    public string $search = '';

    public int $postId = 0;

    public string $orderby = 'date';

    public string $order = 'DESC';

    /** @var array<string, int> status => count */
    public array $statusCounts = [];

    private ?AP_DB $db;

    public function __construct(?AP_DB $db = null)
    {
        $this->db = $db;
    }

    /**
     * @param array<string, mixed> $request
     */
    public function prepareItems(array $request = []): void
    {
        $rawStatus = (string) ($request['comment_status'] ?? $request['status'] ?? 'all');
        $this->statusView = $this->normalizeStatusView($rawStatus);
        $this->search = trim((string) ($request['s'] ?? ''));
        $this->currentPage = max(1, (int) ($request['paged'] ?? 1));
        $this->perPage = max(1, min(100, (int) ($request['per_page'] ?? 20)));
        $this->postId = max(0, (int) ($request['p'] ?? $request['post_id'] ?? 0));

        $orderby = strtolower((string) ($request['orderby'] ?? 'date'));
        $allowed = ['date', 'id', 'author', 'parent'];
        $this->orderby = in_array($orderby, $allowed, true) ? $orderby : 'date';
        $this->order = strtoupper((string) ($request['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $this->statusCounts = AP_Comment::countByStatus(
            $this->postId > 0 ? $this->postId : null,
            $this->resolveDb()
        );
        $this->loadItems();
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
        if (!ap_check_nonce($nonce, 'bulk-comments')) {
            return ['ok' => false, 'message_key' => 'nonce', 'count' => 0, 'errors' => ['Security check failed.']];
        }

        $ids = $post['comment'] ?? $post['comment_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return ['ok' => false, 'message_key' => '', 'count' => 0, 'errors' => ['No comments selected.']];
        }

        $db = $this->resolveDb();
        $count = 0;
        $errors = [];

        foreach ($ids as $id) {
            $item = AP_Comment::get($id, $db);
            if ($item === null) {
                $errors[] = "Comment #{$id} not found.";
                continue;
            }

            $ok = match ($action) {
                'approve' => AP_Comment::approve($id, $db),
                'unapprove' => AP_Comment::unapprove($id, $db),
                'spam' => AP_Comment::spam($id, $db),
                'unspam' => AP_Comment::unspam($id, $db),
                'trash' => AP_Comment::trash($id, $db),
                'untrash' => AP_Comment::untrash($id, $db),
                'delete' => AP_Comment::delete($id, true, $db),
                default => false,
            };

            if ($ok) {
                $count++;
            } else {
                $errors[] = "Could not apply “{$action}” to #{$id}.";
            }
        }

        $messageKey = match ($action) {
            'approve' => 'bulk_comment_approved',
            'unapprove' => 'bulk_comment_unapproved',
            'spam' => 'bulk_comment_spammed',
            'unspam' => 'bulk_comment_unspammed',
            'trash' => 'bulk_comment_trashed',
            'untrash' => 'bulk_comment_untrashed',
            'delete' => 'bulk_comment_deleted',
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
     * Process a single-row GET action.
     *
     * @param array<string, mixed> $request
     *
     * @return array{ok: bool, message_key: string, errors: list<string>}
     */
    public function processRowAction(array $request): array
    {
        $action = (string) ($request['action'] ?? '');
        $id = (int) ($request['c'] ?? $request['comment'] ?? 0);
        if ($id < 1 || $action === '') {
            return ['ok' => false, 'message_key' => 'error', 'errors' => ['Invalid request.']];
        }

        $nonce = (string) ($request['_ap_nonce'] ?? $request['_wpnonce'] ?? '');
        if (!ap_check_nonce($nonce, 'comment-' . $action . '-' . $id)) {
            return ['ok' => false, 'message_key' => 'nonce', 'errors' => ['Security check failed.']];
        }

        $db = $this->resolveDb();
        $item = AP_Comment::get($id, $db);
        if ($item === null) {
            return ['ok' => false, 'message_key' => 'not_found', 'errors' => ['Comment not found.']];
        }

        $ok = match ($action) {
            'approve' => AP_Comment::approve($id, $db),
            'unapprove' => AP_Comment::unapprove($id, $db),
            'spam' => AP_Comment::spam($id, $db),
            'unspam' => AP_Comment::unspam($id, $db),
            'trash' => AP_Comment::trash($id, $db),
            'untrash' => AP_Comment::untrash($id, $db),
            'delete' => AP_Comment::delete($id, true, $db),
            default => false,
        };

        $messageKey = match ($action) {
            'approve' => 'comment_approved',
            'unapprove' => 'comment_unapproved',
            'spam' => 'comment_spammed',
            'unspam' => 'comment_unspammed',
            'trash' => 'comment_trashed',
            'untrash' => 'comment_untrashed',
            'delete' => 'comment_deleted',
            default => 'error',
        };

        return [
            'ok' => $ok,
            'message_key' => $ok ? $messageKey : 'error',
            'errors' => $ok ? [] : ['Could not update the comment.'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getColumns(): array
    {
        return [
            'cb' => '',
            'author' => 'Author',
            'comment' => 'Comment',
            'response' => 'In Response To',
            'date' => 'Submitted On',
            'status' => 'Status',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getBulkActions(): array
    {
        return match ($this->statusView) {
            'trash' => [
                'untrash' => 'Restore',
                'delete' => 'Delete Permanently',
            ],
            'spam' => [
                'unspam' => 'Not Spam',
                'delete' => 'Delete Permanently',
            ],
            'approved' => [
                'unapprove' => 'Unapprove',
                'spam' => 'Mark as Spam',
                'trash' => 'Move to Trash',
            ],
            'moderated' => [
                'approve' => 'Approve',
                'spam' => 'Mark as Spam',
                'trash' => 'Move to Trash',
            ],
            default => [
                'approve' => 'Approve',
                'unapprove' => 'Unapprove',
                'spam' => 'Mark as Spam',
                'trash' => 'Move to Trash',
            ],
        };
    }

    /**
     * @return list<array{key: string, label: string, count: int, current: bool, url: string}>
     */
    public function getViews(): array
    {
        $approved = $this->statusCounts[AP_Comment::STATUS_APPROVED] ?? 0;
        $hold = $this->statusCounts[AP_Comment::STATUS_HOLD] ?? 0;
        $spam = $this->statusCounts[AP_Comment::STATUS_SPAM] ?? 0;
        $trash = $this->statusCounts[AP_Comment::STATUS_TRASH] ?? 0;
        $all = $approved + $hold; // All excludes spam/trash (WP-style)

        $defs = [
            'all' => ['label' => 'All', 'count' => $all],
            'moderated' => ['label' => 'Pending', 'count' => $hold],
            'approved' => ['label' => 'Approved', 'count' => $approved],
            'spam' => ['label' => 'Spam', 'count' => $spam],
            'trash' => ['label' => 'Trash', 'count' => $trash],
        ];

        $views = [];
        foreach ($defs as $key => $def) {
            if ($key !== 'all' && $key !== 'moderated' && $def['count'] < 1 && $this->statusView !== $key) {
                // Always show All + Pending; hide empty spam/trash/approved unless current.
                if (in_array($key, ['spam', 'trash'], true)) {
                    continue;
                }
            }
            $query = ['comment_status' => $key];
            if ($this->postId > 0) {
                $query['p'] = $this->postId;
            }
            if ($this->search !== '') {
                $query['s'] = $this->search;
            }
            $views[] = [
                'key' => $key,
                'label' => $def['label'],
                'count' => $def['count'],
                'current' => $this->statusView === $key,
                'url' => AP_Admin::url('edit-comments.php', $query),
            ];
        }

        return $views;
    }

    public function renderViews(): string
    {
        $parts = [];
        foreach ($this->getViews() as $view) {
            $label = ap_esc_html($view['label']);
            $count = (int) $view['count'];
            $class = $view['current'] ? ' class="current"' : '';
            $parts[] = '<a href="' . ap_esc_url($view['url']) . '"' . $class . '>'
                . $label . ' <span class="count">(' . $count . ')</span></a>';
        }

        return '<ul class="ap-subsubsub"><li>' . implode('</li><li> | </li><li>', $parts) . '</li></ul>';
    }

    public function renderSearchBox(): string
    {
        $s = ap_esc_attr($this->search);
        $html = '<form method="get" action="" class="ap-search-form" role="search">'
            . '<input type="hidden" name="comment_status" value="' . ap_esc_attr($this->statusView) . '">';
        if ($this->postId > 0) {
            $html .= '<input type="hidden" name="p" value="' . (int) $this->postId . '">';
        }
        $html .= '<label class="screen-reader-text" for="comment-search-input">'
            . 'Search comments</label>'
            . '<input type="search" id="comment-search-input" name="s" value="'
            . $s . '" placeholder="Search comments…">'
            . '<button type="submit" class="button">Search Comments</button>'
            . '</form>';

        return $html;
    }

    public function render(): string
    {
        $columns = $this->getColumns();
        $bulk = $this->getBulkActions();
        $nonce = ap_create_nonce('bulk-comments');

        $html = '<form method="post" action="">'
            . '<input type="hidden" name="_ap_nonce" value="' . ap_esc_attr($nonce) . '">'
            . '<input type="hidden" name="comment_status" value="' . ap_esc_attr($this->statusView) . '">';
        if ($this->postId > 0) {
            $html .= '<input type="hidden" name="p" value="' . (int) $this->postId . '">';
        }

        $html .= '<div class="ap-tablenav top">'
            . $this->renderBulkDropdown('action', $bulk)
            . '<button type="submit" class="button action">Apply</button>'
            . $this->renderPagination()
            . '</div>';

        $html .= '<table class="ap-list-table striped widefat">'
            . '<thead><tr>';
        foreach ($columns as $key => $label) {
            if ($key === 'cb') {
                $html .= '<th class="check-column"><input type="checkbox" id="cb-select-all" '
                    . 'aria-label="Select all"></th>';
            } else {
                $html .= '<th scope="col" class="column-' . ap_esc_attr($key) . '">'
                    . ap_esc_html($label) . '</th>';
            }
        }
        $html .= '</tr></thead><tbody>';

        if ($this->items === []) {
            $html .= '<tr><td colspan="' . count($columns) . '">No comments found.</td></tr>';
        } else {
            foreach ($this->items as $comment) {
                $html .= $this->renderRow($comment, $columns);
            }
        }

        $html .= '</tbody></table>';
        $html .= '<div class="ap-tablenav bottom">'
            . $this->renderBulkDropdown('action2', $bulk)
            . '<button type="submit" class="button action">Apply</button>'
            . $this->renderPagination()
            . '</div>';
        $html .= '</form>';

        return $html;
    }

    private function loadItems(): void
    {
        $statusMap = [
            'all' => ['1', '0'],
            'moderated' => 'hold',
            'approved' => 'approve',
            'spam' => 'spam',
            'trash' => 'trash',
        ];
        $status = $statusMap[$this->statusView] ?? 'all';

        $args = [
            'status' => $status,
            'search' => $this->search,
            'orderby' => $this->orderby === 'author' ? 'date' : $this->orderby,
            'order' => $this->order,
            'limit' => $this->perPage,
            'offset' => ($this->currentPage - 1) * $this->perPage,
        ];
        if ($this->postId > 0) {
            $args['post_id'] = $this->postId;
        }

        $this->items = AP_Comment::query($args, $this->resolveDb());
        $this->totalItems = AP_Comment::count([
            'status' => $status,
            'search' => $this->search,
            'post_id' => $this->postId > 0 ? $this->postId : null,
        ], $this->resolveDb());

        // Fix count when post_id is 0 — don't pass null key issue.
        if ($this->postId < 1) {
            $this->totalItems = AP_Comment::count([
                'status' => $status,
                'search' => $this->search,
            ], $this->resolveDb());
        }

        $this->totalPages = max(1, (int) ceil($this->totalItems / $this->perPage));
        if ($this->currentPage > $this->totalPages) {
            $this->currentPage = $this->totalPages;
        }
    }

    /**
     * @param array<string, string> $columns
     */
    private function renderRow(AP_Comment $comment, array $columns): string
    {
        $id = $comment->comment_ID;
        $row = '<tr id="comment-' . $id . '" class="status-'
            . ap_esc_attr($this->statusClass($comment->comment_approved)) . '">';

        foreach ($columns as $key => $label) {
            switch ($key) {
                case 'cb':
                    $row .= '<th scope="row" class="check-column">'
                        . '<input type="checkbox" name="comment[]" value="' . $id . '" '
                        . 'aria-label="Select comment ' . $id . '">'
                        . '</th>';
                    break;
                case 'author':
                    $name = $comment->comment_author !== ''
                        ? $comment->comment_author
                        : ($comment->user_id > 0 ? 'User #' . $comment->user_id : '(anonymous)');
                    $row .= '<td class="column-author" data-colname="Author">'
                        . '<strong>' . ap_esc_html($name) . '</strong>';
                    if ($comment->comment_author_email !== '') {
                        $row .= '<br><a href="mailto:' . ap_esc_attr($comment->comment_author_email) . '">'
                            . ap_esc_html($comment->comment_author_email) . '</a>';
                    }
                    if ($comment->comment_author_IP !== '') {
                        $row .= '<br><span class="ap-muted">' . ap_esc_html($comment->comment_author_IP) . '</span>';
                    }
                    $row .= '</td>';
                    break;
                case 'comment':
                    $excerpt = self::excerpt($comment->comment_content, 200);
                    $row .= '<td class="column-comment" data-colname="Comment">'
                        . '<div class="comment-content">' . ap_esc_html($excerpt) . '</div>'
                        . $this->renderRowActions($comment)
                        . '</td>';
                    break;
                case 'response':
                    $postTitle = 'Post #' . $comment->comment_post_ID;
                    $postUrl = AP_Admin::url('post.php', ['post' => $comment->comment_post_ID, 'action' => 'edit']);
                    if (class_exists('AP_Post', false)) {
                        $post = AP_Post::get($comment->comment_post_ID, $this->resolveDb());
                        if ($post !== null) {
                            $postTitle = $post->post_title !== '' ? $post->post_title : '(no title)';
                        }
                    }
                    $row .= '<td class="column-response" data-colname="In Response To">'
                        . '<a href="' . ap_esc_url($postUrl) . '">' . ap_esc_html($postTitle) . '</a>';
                    if ($comment->comment_parent > 0) {
                        $row .= '<br><span class="ap-muted">In reply to #'
                            . (int) $comment->comment_parent . '</span>';
                    }
                    $row .= '</td>';
                    break;
                case 'date':
                    $row .= '<td class="column-date" data-colname="Submitted On">'
                        . ap_esc_html($comment->comment_date) . '</td>';
                    break;
                case 'status':
                    $row .= '<td class="column-status" data-colname="Status">'
                        . ap_esc_html(AP_Comment::statusLabel($comment->comment_approved)) . '</td>';
                    break;
                default:
                    $row .= '<td></td>';
            }
        }

        $row .= '</tr>';

        return $row;
    }

    private function renderRowActions(AP_Comment $comment): string
    {
        $id = $comment->comment_ID;
        $status = $comment->comment_approved;
        $links = [];

        $actionUrl = static function (string $action, int $cid) use ($comment): string {
            return AP_Admin::url('edit-comments.php', [
                'action' => $action,
                'c' => $cid,
                'comment_status' => '',
                '_ap_nonce' => ap_create_nonce('comment-' . $action . '-' . $cid),
            ]);
        };

        if ($status === AP_Comment::STATUS_HOLD) {
            $links[] = '<a href="' . ap_esc_url($actionUrl('approve', $id)) . '">Approve</a>';
        }
        if ($status === AP_Comment::STATUS_APPROVED) {
            $links[] = '<a href="' . ap_esc_url($actionUrl('unapprove', $id)) . '">Unapprove</a>';
        }
        if ($status === AP_Comment::STATUS_SPAM) {
            $links[] = '<a href="' . ap_esc_url($actionUrl('unspam', $id)) . '">Not Spam</a>';
            $del = ap_esc_url($actionUrl('delete', $id));
            $links[] = '<a class="submitdelete" href="' . $del . '">Delete Permanently</a>';
        } elseif ($status === AP_Comment::STATUS_TRASH) {
            $links[] = '<a href="' . ap_esc_url($actionUrl('untrash', $id)) . '">Restore</a>';
            $del = ap_esc_url($actionUrl('delete', $id));
            $links[] = '<a class="submitdelete" href="' . $del . '">Delete Permanently</a>';
        } else {
            $links[] = '<a href="' . ap_esc_url($actionUrl('spam', $id)) . '">Spam</a>';
            $trash = ap_esc_url($actionUrl('trash', $id));
            $links[] = '<a class="submitdelete" href="' . $trash . '">Trash</a>';
        }

        if ($links === []) {
            return '';
        }

        return '<div class="row-actions">' . implode(' | ', $links) . '</div>';
    }

    /**
     * @param array<string, string> $actions
     */
    private function renderBulkDropdown(string $name, array $actions): string
    {
        $html = '<label class="screen-reader-text" for="' . ap_esc_attr($name) . '">Select bulk action</label>'
            . '<select name="' . ap_esc_attr($name) . '" id="' . ap_esc_attr($name) . '">'
            . '<option value="-1">Bulk actions</option>';
        foreach ($actions as $value => $label) {
            $html .= '<option value="' . ap_esc_attr($value) . '">' . ap_esc_html($label) . '</option>';
        }
        $html .= '</select> ';

        return $html;
    }

    private function renderPagination(): string
    {
        if ($this->totalPages <= 1) {
            return '<span class="ap-displaying-num">' . (int) $this->totalItems . ' items</span>';
        }

        $query = [
            'comment_status' => $this->statusView,
            's' => $this->search !== '' ? $this->search : null,
            'p' => $this->postId > 0 ? $this->postId : null,
        ];
        $prev = max(1, $this->currentPage - 1);
        $next = min($this->totalPages, $this->currentPage + 1);
        $prevUrl = AP_Admin::url('edit-comments.php', array_filter($query + ['paged' => $prev]));
        $nextUrl = AP_Admin::url('edit-comments.php', array_filter($query + ['paged' => $next]));

        return '<span class="ap-displaying-num">' . (int) $this->totalItems . ' items</span> '
            . '<span class="ap-pagination">'
            . ($this->currentPage > 1
                ? '<a class="button" href="' . ap_esc_url($prevUrl) . '">‹</a> '
                : '<span class="button disabled">‹</span> ')
            . (int) $this->currentPage . ' of ' . (int) $this->totalPages . ' '
            . ($this->currentPage < $this->totalPages
                ? '<a class="button" href="' . ap_esc_url($nextUrl) . '">›</a>'
                : '<span class="button disabled">›</span>')
            . '</span>';
    }

    private function normalizeStatusView(string $raw): string
    {
        $raw = strtolower(trim($raw));

        return match ($raw) {
            'moderated', 'hold', 'pending', '0' => 'moderated',
            'approved', 'approve', '1' => 'approved',
            'spam' => 'spam',
            'trash' => 'trash',
            default => 'all',
        };
    }

    private function statusClass(string $approved): string
    {
        return match ($approved) {
            AP_Comment::STATUS_APPROVED => 'approved',
            AP_Comment::STATUS_HOLD => 'pending',
            AP_Comment::STATUS_SPAM => 'spam',
            AP_Comment::STATUS_TRASH => 'trash',
            default => 'pending',
        };
    }

    private static function excerpt(string $text, int $max = 200): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max - 1) . '…';
    }

    private function resolveDb(): AP_DB
    {
        if ($this->db instanceof AP_DB) {
            return $this->db;
        }

        return ap_db();
    }
}
