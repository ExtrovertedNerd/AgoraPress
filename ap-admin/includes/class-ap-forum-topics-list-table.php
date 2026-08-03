<?php

/**
 * Admin topics list table with bulk moderation actions.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Forum topics list renderer and bulk-action processor.
 */
class AP_Forum_Topics_List_Table
{
    /** @var list<object> */
    public array $items = [];

    public int $totalItems = 0;

    public int $perPage = 20;

    public int $currentPage = 1;

    public int $totalPages = 1;

    /** all|open|locked|deleted|pending|sticky */
    public string $statusView = 'all';

    public string $search = '';

    public int $forumId = 0;

    /** @var array<string, int> */
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
        $this->statusView = $this->normalizeStatusView((string) ($request['topic_status'] ?? 'all'));
        $this->search = trim((string) ($request['s'] ?? ''));
        $this->currentPage = max(1, (int) ($request['paged'] ?? 1));
        $this->perPage = max(1, min(100, (int) ($request['per_page'] ?? 20)));
        $this->forumId = max(0, (int) ($request['forum_id'] ?? 0));

        $this->statusCounts = $this->countByStatus();
        $this->loadItems();
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return array{ok: bool, message_key: string, count: int, errors: list<string>}
     */
    public function processBulkAction(array $post, int $actorId = 0): array
    {
        $action = (string) ($post['action'] ?? $post['action2'] ?? '-1');
        if ($action === '' || $action === '-1') {
            return ['ok' => false, 'message_key' => '', 'count' => 0, 'errors' => ['No bulk action selected.']];
        }

        $db = $this->resolveDb();
        if ($actorId < 1 && function_exists('ap_get_current_user_id')) {
            $actorId = ap_get_current_user_id($db);
        }

        $nonce = (string) ($post['_ap_nonce'] ?? '');
        if (!ap_check_nonce($nonce, 'bulk-forum-topics', $actorId > 0 ? $actorId : null)) {
            return ['ok' => false, 'message_key' => 'nonce', 'count' => 0, 'errors' => ['Security check failed.']];
        }

        if (!AP_Admin::userCan($actorId, 'moderate_forums', null, $db)) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'count' => 0,
                'errors' => ['You do not have permission to moderate forums.'],
            ];
        }

        $ids = $post['topic'] ?? $post['topic_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return ['ok' => false, 'message_key' => '', 'count' => 0, 'errors' => ['No topics selected.']];
        }

        $count = 0;
        $errors = [];
        foreach ($ids as $id) {
            $ok = $this->applyAction($action, $id, $actorId, $db);
            if ($ok) {
                $count++;
            } else {
                $errors[] = "Could not apply “{$action}” to topic #{$id}.";
            }
        }

        $messageKey = match ($action) {
            'lock' => 'bulk_topic_locked',
            'unlock' => 'bulk_topic_unlocked',
            'sticky' => 'bulk_topic_sticky',
            'unsticky' => 'bulk_topic_unsticky',
            'approve' => 'bulk_topic_approved',
            'unapprove' => 'bulk_topic_unapproved',
            'trash', 'soft_delete' => 'bulk_topic_trashed',
            'restore' => 'bulk_topic_restored',
            'delete' => 'bulk_topic_deleted',
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
     * @param array<string, mixed> $request
     *
     * @return array{ok: bool, message_key: string, errors: list<string>}
     */
    public function processRowAction(array $request, int $actorId = 0): array
    {
        $action = (string) ($request['action'] ?? '');
        $id = (int) ($request['topic'] ?? $request['t'] ?? 0);
        if ($id < 1 || $action === '') {
            return ['ok' => false, 'message_key' => 'error', 'errors' => ['Invalid request.']];
        }

        $db = $this->resolveDb();
        if ($actorId < 1 && function_exists('ap_get_current_user_id')) {
            $actorId = ap_get_current_user_id($db);
        }

        $nonce = (string) ($request['_ap_nonce'] ?? $request['_wpnonce'] ?? '');
        if (!ap_check_nonce($nonce, 'topic-' . $action . '-' . $id, $actorId > 0 ? $actorId : null)) {
            return ['ok' => false, 'message_key' => 'nonce', 'errors' => ['Security check failed.']];
        }

        if (!AP_Admin::userCan($actorId, 'moderate_forums', null, $db)) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'errors' => ['You do not have permission to moderate forums.'],
            ];
        }

        $ok = $this->applyAction($action, $id, $actorId, $db);
        $messageKey = match ($action) {
            'lock' => 'topic_locked',
            'unlock' => 'topic_unlocked',
            'sticky' => 'topic_sticky',
            'unsticky' => 'topic_unsticky',
            'approve' => 'topic_approved',
            'unapprove' => 'topic_unapproved',
            'trash', 'soft_delete' => 'topic_trashed',
            'restore' => 'topic_restored',
            'delete' => 'topic_deleted',
            default => 'error',
        };

        return [
            'ok' => $ok,
            'message_key' => $ok ? $messageKey : 'error',
            'errors' => $ok ? [] : ['Could not update the topic.'],
        ];
    }

    private function applyAction(string $action, int $id, int $actorId, AP_DB $db): bool
    {
        return match ($action) {
            'lock' => AP_Forum_Moderation::lockTopic($id, $actorId, $db),
            'unlock' => AP_Forum_Moderation::unlockTopic($id, $actorId, $db),
            'sticky' => AP_Forum_Moderation::setTopicType($id, AP_Forum::TOPIC_TYPE_STICKY, $actorId, $db),
            'unsticky' => AP_Forum_Moderation::setTopicType($id, AP_Forum::TOPIC_TYPE_NORMAL, $actorId, $db),
            'approve' => AP_Forum_Moderation::approveTopic($id, $actorId, $db),
            'unapprove' => AP_Forum_Moderation::unapproveTopic($id, $actorId, $db),
            'trash', 'soft_delete' => AP_Forum_Moderation::softDeleteTopic($id, $actorId, $db),
            'restore' => AP_Forum_Moderation::restoreTopic($id, $actorId, $db),
            'delete' => AP_Forum_Moderation::forceDeleteTopic($id, $actorId, $db),
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public function getColumns(): array
    {
        return [
            'cb' => '',
            'title' => 'Title',
            'forum' => 'Forum',
            'author' => 'Author',
            'replies' => 'Replies',
            'last_post' => 'Last Post',
            'status' => 'Status',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getBulkActions(): array
    {
        if ($this->statusView === 'deleted') {
            return [
                'restore' => 'Restore',
                'delete' => 'Delete permanently',
            ];
        }
        if ($this->statusView === 'pending') {
            return [
                'approve' => 'Approve',
                'trash' => 'Soft-delete',
            ];
        }

        return [
            'lock' => 'Lock',
            'unlock' => 'Unlock',
            'sticky' => 'Make sticky',
            'unsticky' => 'Remove sticky',
            'approve' => 'Approve',
            'unapprove' => 'Unapprove',
            'trash' => 'Soft-delete',
            'delete' => 'Delete permanently',
        ];
    }

    /**
     * @return list<array{key: string, label: string, count: int, current: bool, url: string}>
     */
    public function getViews(): array
    {
        $defs = [
            'all' => ['label' => 'All', 'count' => $this->statusCounts['all'] ?? 0],
            'open' => ['label' => 'Open', 'count' => $this->statusCounts['open'] ?? 0],
            'locked' => ['label' => 'Locked', 'count' => $this->statusCounts['locked'] ?? 0],
            'pending' => ['label' => 'Pending', 'count' => $this->statusCounts['pending'] ?? 0],
            'sticky' => ['label' => 'Sticky', 'count' => $this->statusCounts['sticky'] ?? 0],
            'deleted' => ['label' => 'Deleted', 'count' => $this->statusCounts['deleted'] ?? 0],
        ];

        $views = [];
        foreach ($defs as $key => $def) {
            $query = ['topic_status' => $key];
            if ($this->forumId > 0) {
                $query['forum_id'] = $this->forumId;
            }
            if ($this->search !== '') {
                $query['s'] = $this->search;
            }
            $views[] = [
                'key' => $key,
                'label' => $def['label'],
                'count' => $def['count'],
                'current' => $this->statusView === $key,
                'url' => AP_Admin::url('forum-topics.php', $query),
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
            . '<input type="hidden" name="topic_status" value="' . ap_esc_attr($this->statusView) . '">';
        if ($this->forumId > 0) {
            $html .= '<input type="hidden" name="forum_id" value="' . $this->forumId . '">';
        }
        $html .= '<label class="screen-reader-text" for="topic-search-input">Search topics</label>'
            . '<input type="search" id="topic-search-input" name="s" value="' . $s
            . '" placeholder="Search topics…">'
            . '<button type="submit" class="button">Search Topics</button>'
            . '</form>';

        return $html;
    }

    public function render(): string
    {
        $columns = $this->getColumns();
        $bulk = $this->getBulkActions();
        $nonce = ap_create_nonce('bulk-forum-topics');

        $html = '<form method="post" action="" class="ap-list-table-form">'
            . '<input type="hidden" name="_ap_nonce" value="' . ap_esc_attr($nonce) . '">'
            . '<input type="hidden" name="topic_status" value="' . ap_esc_attr($this->statusView) . '">';
        if ($this->forumId > 0) {
            $html .= '<input type="hidden" name="forum_id" value="' . $this->forumId . '">';
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
            $html .= '<tr><td colspan="' . count($columns) . '">No topics found.</td></tr>';
        } else {
            foreach ($this->items as $topic) {
                $html .= $this->renderRow($topic, $columns);
            }
        }

        $html .= '</tbody></table>';
        $html .= '<div class="ap-tablenav bottom">'
            . $this->renderBulkDropdown('action2', $bulk)
            . '<button type="submit" class="button action">Apply</button>'
            . $this->renderPagination()
            . '</div></form>';

        return $html;
    }

    private function loadItems(): void
    {
        $args = $this->queryArgs();
        $args['per_page'] = $this->perPage;
        $args['page'] = $this->currentPage;

        $db = $this->resolveDb();
        $this->items = AP_Forum::queryTopics($args, $db);
        $this->totalItems = AP_Forum::countTopicsQuery($args, $db);
        $this->totalPages = max(1, (int) ceil($this->totalItems / $this->perPage));
        if ($this->currentPage > $this->totalPages) {
            $this->currentPage = $this->totalPages;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function queryArgs(): array
    {
        $args = [
            'search' => $this->search,
            'include_deleted' => $this->statusView === 'deleted' || $this->statusView === 'all',
            'approved_only' => null,
        ];
        if ($this->forumId > 0) {
            $args['forum_id'] = $this->forumId;
        }

        switch ($this->statusView) {
            case 'open':
                $args['status'] = AP_Forum::TOPIC_STATUS_OPEN;
                $args['include_deleted'] = false;
                $args['approved_only'] = true;
                break;
            case 'locked':
                $args['status'] = AP_Forum::TOPIC_STATUS_LOCKED;
                $args['include_deleted'] = false;
                break;
            case 'deleted':
                $args['status'] = AP_Forum::TOPIC_STATUS_DELETED;
                $args['include_deleted'] = true;
                break;
            case 'pending':
                $args['pending_only'] = true;
                $args['include_deleted'] = false;
                break;
            case 'sticky':
                $args['type'] = AP_Forum::TOPIC_TYPE_STICKY;
                $args['include_deleted'] = false;
                break;
            default:
                $args['include_deleted'] = false;
                break;
        }

        return $args;
    }

    /**
     * @return array<string, int>
     */
    private function countByStatus(): array
    {
        $db = $this->resolveDb();
        $base = $this->forumId > 0 ? ['forum_id' => $this->forumId] : [];

        return [
            'all' => AP_Forum::countTopicsQuery($base + ['include_deleted' => false], $db),
            'open' => AP_Forum::countTopicsQuery($base + [
                'status' => AP_Forum::TOPIC_STATUS_OPEN,
                'approved_only' => true,
            ], $db),
            'locked' => AP_Forum::countTopicsQuery($base + [
                'status' => AP_Forum::TOPIC_STATUS_LOCKED,
            ], $db),
            'pending' => AP_Forum::countTopicsQuery($base + [
                'pending_only' => true,
            ], $db),
            'sticky' => AP_Forum::countTopicsQuery($base + [
                'type' => AP_Forum::TOPIC_TYPE_STICKY,
            ], $db),
            'deleted' => AP_Forum::countTopicsQuery($base + [
                'status' => AP_Forum::TOPIC_STATUS_DELETED,
                'include_deleted' => true,
            ], $db),
        ];
    }

    /**
     * @param array<string, string> $columns
     */
    private function renderRow(object $topic, array $columns): string
    {
        $id = (int) $topic->topic_id;
        $title = (string) $topic->topic_title;
        if ($title === '') {
            $title = '(no title)';
        }

        $forumName = 'Forum #' . (int) $topic->forum_id;
        $forum = AP_Forum::getForum((int) $topic->forum_id, $this->resolveDb());
        if ($forum !== null) {
            $forumName = (string) $forum->forum_name;
        }

        $author = 'User #' . (int) $topic->topic_poster;
        if (class_exists('AP_User', false) && (int) $topic->topic_poster > 0) {
            $user = AP_User::getById((int) $topic->topic_poster, $this->resolveDb());
            if ($user !== null) {
                $author = $user->display_name !== ''
                    ? (string) $user->display_name
                    : (string) $user->user_login;
            }
        }

        $statusBits = [];
        $statusBits[] = ucfirst((string) $topic->topic_status);
        if ((int) $topic->topic_approved !== 1) {
            $statusBits[] = 'Pending';
        }
        $type = (string) $topic->topic_type;
        if ($type !== AP_Forum::TOPIC_TYPE_NORMAL) {
            $statusBits[] = ucfirst($type);
        }

        $row = '<tr id="topic-' . $id . '">';
        foreach ($columns as $key => $_label) {
            switch ($key) {
                case 'cb':
                    $row .= '<th scope="row" class="check-column">'
                        . '<input type="checkbox" name="topic[]" value="' . $id . '" '
                        . 'aria-label="Select topic ' . $id . '">'
                        . '</th>';
                    break;
                case 'title':
                    $row .= '<td class="column-title" data-colname="Title">'
                        . '<strong class="row-title">' . ap_esc_html($title) . '</strong>'
                        . $this->renderRowActions($topic)
                        . '</td>';
                    break;
                case 'forum':
                    $row .= '<td class="column-forum" data-colname="Forum">'
                        . ap_esc_html($forumName) . '</td>';
                    break;
                case 'author':
                    $row .= '<td class="column-author" data-colname="Author">'
                        . ap_esc_html($author) . '</td>';
                    break;
                case 'replies':
                    $replies = (int) ($topic->reply_count ?? $topic->topic_replies ?? 0);
                    $row .= '<td class="column-replies" data-colname="Replies">'
                        . $replies . '</td>';
                    break;
                case 'last_post':
                    $last = (string) ($topic->topic_last_post_time ?? '');
                    if ($last === '' || $last === AP_Forum::EMPTY_DATETIME) {
                        $last = (string) ($topic->topic_time ?? '');
                    }
                    $row .= '<td class="column-last_post" data-colname="Last Post">'
                        . ap_esc_html($last) . '</td>';
                    break;
                case 'status':
                    $row .= '<td class="column-status" data-colname="Status">'
                        . ap_esc_html(implode(' · ', $statusBits)) . '</td>';
                    break;
                default:
                    $row .= '<td></td>';
            }
        }
        $row .= '</tr>';

        return $row;
    }

    private function renderRowActions(object $topic): string
    {
        $id = (int) $topic->topic_id;
        $status = (string) $topic->topic_status;
        $actions = [];

        $baseQuery = array_filter([
            'topic_status' => $this->statusView !== 'all' ? $this->statusView : null,
            'forum_id' => $this->forumId > 0 ? $this->forumId : null,
        ]);

        if ($status === AP_Forum::TOPIC_STATUS_DELETED) {
            $actions['restore'] = [
                'label' => 'Restore',
                'url' => ap_nonce_url(
                    AP_Admin::url('forum-topics.php', $baseQuery + ['action' => 'restore', 'topic' => $id]),
                    'topic-restore-' . $id
                ),
            ];
            $actions['delete'] = [
                'label' => 'Delete permanently',
                'url' => ap_nonce_url(
                    AP_Admin::url('forum-topics.php', $baseQuery + ['action' => 'delete', 'topic' => $id]),
                    'topic-delete-' . $id
                ),
            ];
        } else {
            if ((int) $topic->topic_approved !== 1) {
                $actions['approve'] = [
                    'label' => 'Approve',
                    'url' => ap_nonce_url(
                        AP_Admin::url('forum-topics.php', $baseQuery + ['action' => 'approve', 'topic' => $id]),
                        'topic-approve-' . $id
                    ),
                ];
            }
            if ($status === AP_Forum::TOPIC_STATUS_LOCKED) {
                $actions['unlock'] = [
                    'label' => 'Unlock',
                    'url' => ap_nonce_url(
                        AP_Admin::url('forum-topics.php', $baseQuery + ['action' => 'unlock', 'topic' => $id]),
                        'topic-unlock-' . $id
                    ),
                ];
            } else {
                $actions['lock'] = [
                    'label' => 'Lock',
                    'url' => ap_nonce_url(
                        AP_Admin::url('forum-topics.php', $baseQuery + ['action' => 'lock', 'topic' => $id]),
                        'topic-lock-' . $id
                    ),
                ];
            }
            $actions['trash'] = [
                'label' => 'Soft-delete',
                'url' => ap_nonce_url(
                    AP_Admin::url('forum-topics.php', $baseQuery + ['action' => 'trash', 'topic' => $id]),
                    'topic-trash-' . $id
                ),
            ];
        }

        $parts = [];
        foreach ($actions as $key => $action) {
            $class = $key === 'trash' || $key === 'delete' ? ' class="submitdelete"' : '';
            $parts[] = '<span class="' . ap_esc_attr($key) . '">'
                . '<a href="' . ap_esc_url($action['url']) . '"' . $class . '>'
                . ap_esc_html($action['label']) . '</a></span>';
        }

        return $parts === [] ? '' : '<div class="row-actions">' . implode(' | ', $parts) . '</div>';
    }

    /**
     * @param array<string, string> $actions
     */
    private function renderBulkDropdown(string $name, array $actions): string
    {
        $html = '<label class="screen-reader-text" for="' . ap_esc_attr($name)
            . '">Select bulk action</label>'
            . '<select name="' . ap_esc_attr($name) . '" id="' . ap_esc_attr($name) . '">'
            . '<option value="-1">Bulk actions</option>';
        foreach ($actions as $value => $label) {
            $html .= '<option value="' . ap_esc_attr($value) . '">'
                . ap_esc_html($label) . '</option>';
        }
        $html .= '</select>';

        return $html;
    }

    private function renderPagination(): string
    {
        if ($this->totalPages <= 1) {
            return '';
        }
        $prev = max(1, $this->currentPage - 1);
        $next = min($this->totalPages, $this->currentPage + 1);
        $query = array_filter([
            'topic_status' => $this->statusView !== 'all' ? $this->statusView : null,
            'forum_id' => $this->forumId > 0 ? $this->forumId : null,
            's' => $this->search !== '' ? $this->search : null,
        ]);
        $prevUrl = AP_Admin::url('forum-topics.php', $query + ['paged' => $prev]);
        $nextUrl = AP_Admin::url('forum-topics.php', $query + ['paged' => $next]);

        return '<div class="ap-tablenav-pages">'
            . '<span class="displaying-num">' . $this->totalItems . ' items</span> '
            . '<a class="button" href="' . ap_esc_url($prevUrl) . '"'
            . ($this->currentPage <= 1 ? ' aria-disabled="true"' : '') . '>‹</a> '
            . '<span class="paging-input">' . $this->currentPage . ' of ' . $this->totalPages . '</span> '
            . '<a class="button" href="' . ap_esc_url($nextUrl) . '"'
            . ($this->currentPage >= $this->totalPages ? ' aria-disabled="true"' : '') . '>›</a>'
            . '</div>';
    }

    private function normalizeStatusView(string $raw): string
    {
        $raw = strtolower(trim($raw));
        $allowed = ['all', 'open', 'locked', 'deleted', 'pending', 'sticky'];

        return in_array($raw, $allowed, true) ? $raw : 'all';
    }

    private function resolveDb(): AP_DB
    {
        if ($this->db instanceof AP_DB) {
            return $this->db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('Database connection required.');
    }
}
