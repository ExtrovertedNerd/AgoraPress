<?php

/**
 * Admin moderation queue: pending topics/posts and user reports.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Forum moderation queue list + action handlers.
 */
class AP_Forum_Moderation_Queue
{
    /** pending|reports */
    public string $view = 'pending';

    /** @var list<object> */
    public array $pendingTopics = [];

    /** @var list<object> */
    public array $pendingPosts = [];

    /** @var list<object> */
    public array $reports = [];

    public int $pendingTopicCount = 0;

    public int $pendingPostCount = 0;

    public int $openReportCount = 0;

    private ?AP_DB $db;

    public function __construct(?AP_DB $db = null)
    {
        $this->db = $db;
    }

    /**
     * @param array<string, mixed> $request
     */
    public function prepare(array $request = []): void
    {
        $view = strtolower(trim((string) ($request['view'] ?? 'pending')));
        $this->view = in_array($view, ['pending', 'reports'], true) ? $view : 'pending';

        $db = $this->resolveDb();
        $this->pendingTopicCount = AP_Forum::countPendingTopics([], $db);
        $this->pendingPostCount = AP_Forum::countPendingPosts([], $db);
        $this->openReportCount = AP_Forum_Moderation::countReports([
            'status' => AP_Forum_Moderation::REPORT_STATUS_OPEN,
        ], $db);

        if ($this->view === 'reports') {
            $status = strtolower(trim((string) ($request['report_status'] ?? 'open')));
            if ($status === 'all') {
                $this->reports = AP_Forum_Moderation::queryReports([
                    'per_page' => 50,
                    'page' => max(1, (int) ($request['paged'] ?? 1)),
                ], $db);
            } else {
                if (!in_array($status, AP_Forum_Moderation::reportStatuses(), true)) {
                    $status = AP_Forum_Moderation::REPORT_STATUS_OPEN;
                }
                $this->reports = AP_Forum_Moderation::queryReports([
                    'status' => $status,
                    'per_page' => 50,
                    'page' => max(1, (int) ($request['paged'] ?? 1)),
                ], $db);
            }
        } else {
            $this->pendingTopics = AP_Forum::getPendingTopics(['per_page' => 50], $db);
            $this->pendingPosts = AP_Forum::getPendingPosts(['per_page' => 50], $db);
        }
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{ok: bool, message_key: string, errors: list<string>}
     */
    public function processAction(array $request, int $actorId = 0): array
    {
        $action = (string) ($request['action'] ?? '');
        $db = $this->resolveDb();
        if ($actorId < 1 && function_exists('ap_get_current_user_id')) {
            $actorId = ap_get_current_user_id($db);
        }

        if (!AP_Admin::userCan($actorId, 'moderate_forums', null, $db)) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'errors' => ['You do not have permission to moderate forums.'],
            ];
        }

        return match ($action) {
            'approve_topic' => $this->actTopic('approve', $request, $actorId, $db),
            'reject_topic', 'trash_topic' => $this->actTopic('trash', $request, $actorId, $db),
            'approve_post' => $this->actPost('approve', $request, $actorId, $db),
            'reject_post', 'trash_post' => $this->actPost('trash', $request, $actorId, $db),
            'resolve_report' => $this->actReport('resolve', $request, $actorId, $db),
            'dismiss_report' => $this->actReport('dismiss', $request, $actorId, $db),
            'reopen_report' => $this->actReport('reopen', $request, $actorId, $db),
            default => ['ok' => false, 'message_key' => 'error', 'errors' => ['Unknown action.']],
        };
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return array{ok: bool, message_key: string, count: int, errors: list<string>}
     */
    public function processBulk(array $post, int $actorId = 0): array
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
        if (!ap_check_nonce($nonce, 'bulk-forum-moderation', $actorId > 0 ? $actorId : null)) {
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

        $count = 0;
        $errors = [];

        if (str_starts_with($action, 'report_')) {
            $ids = $post['report'] ?? [];
            if (!is_array($ids)) {
                $ids = [];
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            $sub = substr($action, 7); // resolve / dismiss
            foreach ($ids as $id) {
                $ok = match ($sub) {
                    'resolve' => AP_Forum_Moderation::resolveReport($id, $actorId, $db),
                    'dismiss' => AP_Forum_Moderation::dismissReport($id, $actorId, $db),
                    default => false,
                };
                if ($ok) {
                    $count++;
                } else {
                    $errors[] = "Could not update report #{$id}.";
                }
            }
            $key = $sub === 'resolve' ? 'bulk_report_resolved' : 'bulk_report_dismissed';

            return [
                'ok' => $count > 0,
                'message_key' => $count > 0 ? $key : 'error',
                'count' => $count,
                'errors' => $errors,
            ];
        }

        if (str_starts_with($action, 'topic_')) {
            $ids = $post['topic'] ?? [];
            if (!is_array($ids)) {
                $ids = [];
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            $sub = substr($action, 6);
            foreach ($ids as $id) {
                $ok = match ($sub) {
                    'approve' => AP_Forum_Moderation::approveTopic($id, $actorId, $db),
                    'trash' => AP_Forum_Moderation::softDeleteTopic($id, $actorId, $db),
                    default => false,
                };
                if ($ok) {
                    $count++;
                }
            }

            return [
                'ok' => $count > 0,
                'message_key' => $count > 0
                    ? ($sub === 'approve' ? 'bulk_topic_approved' : 'bulk_topic_trashed')
                    : 'error',
                'count' => $count,
                'errors' => $errors,
            ];
        }

        if (str_starts_with($action, 'post_')) {
            $ids = $post['post'] ?? [];
            if (!is_array($ids)) {
                $ids = [];
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            $sub = substr($action, 5);
            foreach ($ids as $id) {
                $ok = match ($sub) {
                    'approve' => AP_Forum_Moderation::approvePost($id, $actorId, $db),
                    'trash' => AP_Forum_Moderation::softDeletePost($id, $actorId, $db),
                    default => false,
                };
                if ($ok) {
                    $count++;
                }
            }

            return [
                'ok' => $count > 0,
                'message_key' => $count > 0
                    ? ($sub === 'approve' ? 'bulk_forum_post_approved' : 'bulk_forum_post_trashed')
                    : 'error',
                'count' => $count,
                'errors' => $errors,
            ];
        }

        return ['ok' => false, 'message_key' => 'error', 'count' => 0, 'errors' => ['Unknown bulk action.']];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{ok: bool, message_key: string, errors: list<string>}
     */
    private function actTopic(string $kind, array $request, int $actorId, AP_DB $db): array
    {
        $id = (int) ($request['topic'] ?? $request['t'] ?? 0);
        if ($id < 1) {
            return ['ok' => false, 'message_key' => 'error', 'errors' => ['Invalid topic.']];
        }
        $nonce = (string) ($request['_ap_nonce'] ?? $request['_wpnonce'] ?? '');
        $action = $kind === 'approve' ? 'approve_topic' : 'trash_topic';
        if (!ap_check_nonce($nonce, 'mod-' . $action . '-' . $id, $actorId > 0 ? $actorId : null)) {
            return ['ok' => false, 'message_key' => 'nonce', 'errors' => ['Security check failed.']];
        }
        $ok = $kind === 'approve'
            ? AP_Forum_Moderation::approveTopic($id, $actorId, $db)
            : AP_Forum_Moderation::softDeleteTopic($id, $actorId, $db);

        return [
            'ok' => $ok,
            'message_key' => $ok ? ($kind === 'approve' ? 'topic_approved' : 'topic_trashed') : 'error',
            'errors' => $ok ? [] : ['Could not update topic.'],
        ];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{ok: bool, message_key: string, errors: list<string>}
     */
    private function actPost(string $kind, array $request, int $actorId, AP_DB $db): array
    {
        $id = (int) ($request['post'] ?? $request['p'] ?? 0);
        if ($id < 1) {
            return ['ok' => false, 'message_key' => 'error', 'errors' => ['Invalid post.']];
        }
        $nonce = (string) ($request['_ap_nonce'] ?? $request['_wpnonce'] ?? '');
        $action = $kind === 'approve' ? 'approve_post' : 'trash_post';
        if (!ap_check_nonce($nonce, 'mod-' . $action . '-' . $id, $actorId > 0 ? $actorId : null)) {
            return ['ok' => false, 'message_key' => 'nonce', 'errors' => ['Security check failed.']];
        }
        $ok = $kind === 'approve'
            ? AP_Forum_Moderation::approvePost($id, $actorId, $db)
            : AP_Forum_Moderation::softDeletePost($id, $actorId, $db);

        return [
            'ok' => $ok,
            'message_key' => $ok
                ? ($kind === 'approve' ? 'forum_post_approved' : 'forum_post_trashed')
                : 'error',
            'errors' => $ok ? [] : ['Could not update post.'],
        ];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{ok: bool, message_key: string, errors: list<string>}
     */
    private function actReport(string $kind, array $request, int $actorId, AP_DB $db): array
    {
        $id = (int) ($request['report'] ?? $request['r'] ?? 0);
        if ($id < 1) {
            return ['ok' => false, 'message_key' => 'error', 'errors' => ['Invalid report.']];
        }
        $nonce = (string) ($request['_ap_nonce'] ?? $request['_wpnonce'] ?? '');
        $action = $kind . '_report';
        if (!ap_check_nonce($nonce, 'mod-' . $action . '-' . $id, $actorId > 0 ? $actorId : null)) {
            return ['ok' => false, 'message_key' => 'nonce', 'errors' => ['Security check failed.']];
        }
        $ok = match ($kind) {
            'resolve' => AP_Forum_Moderation::resolveReport($id, $actorId, $db),
            'dismiss' => AP_Forum_Moderation::dismissReport($id, $actorId, $db),
            'reopen' => AP_Forum_Moderation::reopenReport($id, $db),
            default => false,
        };

        return [
            'ok' => $ok,
            'message_key' => $ok
                ? match ($kind) {
                    'resolve' => 'report_resolved',
                    'dismiss' => 'report_dismissed',
                    'reopen' => 'report_reopened',
                    default => 'updated',
                }
                : 'error',
            'errors' => $ok ? [] : ['Could not update report.'],
        ];
    }

    public function renderViews(): string
    {
        $pendingTotal = $this->pendingTopicCount + $this->pendingPostCount;
        $views = [
            [
                'key' => 'pending',
                'label' => 'Pending',
                'count' => $pendingTotal,
                'url' => AP_Admin::url('forum-moderation.php', ['view' => 'pending']),
            ],
            [
                'key' => 'reports',
                'label' => 'Reports',
                'count' => $this->openReportCount,
                'url' => AP_Admin::url('forum-moderation.php', ['view' => 'reports']),
            ],
        ];
        $parts = [];
        foreach ($views as $view) {
            $class = $this->view === $view['key'] ? ' class="current"' : '';
            $parts[] = '<a href="' . ap_esc_url($view['url']) . '"' . $class . '>'
                . ap_esc_html($view['label'])
                . ' <span class="count">(' . (int) $view['count'] . ')</span></a>';
        }

        return '<ul class="ap-subsubsub"><li>' . implode('</li><li> | </li><li>', $parts) . '</li></ul>';
    }

    public function render(): string
    {
        if ($this->view === 'reports') {
            return $this->renderReports();
        }

        return $this->renderPending();
    }

    private function renderPending(): string
    {
        $nonce = ap_create_nonce('bulk-forum-moderation');
        $html = '';

        // Pending topics
        $html .= '<h2>Pending topics</h2>';
        $html .= '<form method="post" action="" class="ap-list-table-form">'
            . '<input type="hidden" name="_ap_nonce" value="' . ap_esc_attr($nonce) . '">'
            . '<input type="hidden" name="view" value="pending">';
        $html .= '<div class="ap-tablenav top">'
            . $this->bulkSelect('action', [
                'topic_approve' => 'Approve',
                'topic_trash' => 'Soft-delete',
            ])
            . '<button type="submit" class="button action">Apply</button></div>';
        $html .= '<table class="ap-list-table striped widefat"><thead><tr>'
            . '<th class="check-column"><input type="checkbox" aria-label="Select all topics"></th>'
            . '<th>Title</th><th>Forum</th><th>Author</th><th>When</th><th>Actions</th>'
            . '</tr></thead><tbody>';

        if ($this->pendingTopics === []) {
            $html .= '<tr><td colspan="6">No pending topics.</td></tr>';
        } else {
            foreach ($this->pendingTopics as $topic) {
                $id = (int) $topic->topic_id;
                $title = (string) $topic->topic_title;
                $forumName = $this->forumName((int) $topic->forum_id);
                $approve = ap_nonce_url(
                    AP_Admin::url('forum-moderation.php', [
                        'action' => 'approve_topic',
                        'topic' => $id,
                        'view' => 'pending',
                    ]),
                    'mod-approve_topic-' . $id
                );
                $trash = ap_nonce_url(
                    AP_Admin::url('forum-moderation.php', [
                        'action' => 'trash_topic',
                        'topic' => $id,
                        'view' => 'pending',
                    ]),
                    'mod-trash_topic-' . $id
                );
                $html .= '<tr>'
                    . '<th class="check-column"><input type="checkbox" name="topic[]" value="' . $id . '"></th>'
                    . '<td><strong>' . ap_esc_html($title !== '' ? $title : '(no title)') . '</strong></td>'
                    . '<td>' . ap_esc_html($forumName) . '</td>'
                    . '<td>' . ap_esc_html($this->userLabel((int) $topic->topic_poster)) . '</td>'
                    . '<td>' . ap_esc_html((string) $topic->topic_time) . '</td>'
                    . '<td><a href="' . ap_esc_url($approve) . '">Approve</a> | '
                    . '<a class="submitdelete" href="' . ap_esc_url($trash) . '">Reject</a></td>'
                    . '</tr>';
            }
        }
        $html .= '</tbody></table></form>';

        // Pending posts
        $html .= '<h2>Pending replies</h2>';
        $html .= '<form method="post" action="" class="ap-list-table-form">'
            . '<input type="hidden" name="_ap_nonce" value="' . ap_esc_attr($nonce) . '">'
            . '<input type="hidden" name="view" value="pending">';
        $html .= '<div class="ap-tablenav top">'
            . $this->bulkSelect('action', [
                'post_approve' => 'Approve',
                'post_trash' => 'Soft-delete',
            ])
            . '<button type="submit" class="button action">Apply</button></div>';
        $html .= '<table class="ap-list-table striped widefat"><thead><tr>'
            . '<th class="check-column"><input type="checkbox" aria-label="Select all posts"></th>'
            . '<th>Excerpt</th><th>Topic</th><th>Author</th><th>When</th><th>Actions</th>'
            . '</tr></thead><tbody>';

        if ($this->pendingPosts === []) {
            $html .= '<tr><td colspan="6">No pending replies.</td></tr>';
        } else {
            foreach ($this->pendingPosts as $post) {
                $id = (int) $post->post_id;
                $excerpt = self::excerpt((string) ($post->post_text ?? ''), 120);
                $topicTitle = 'Topic #' . (int) $post->topic_id;
                $topic = AP_Forum::getTopic((int) $post->topic_id, $this->resolveDb());
                if ($topic !== null) {
                    $topicTitle = (string) $topic->topic_title;
                }
                $approve = ap_nonce_url(
                    AP_Admin::url('forum-moderation.php', [
                        'action' => 'approve_post',
                        'post' => $id,
                        'view' => 'pending',
                    ]),
                    'mod-approve_post-' . $id
                );
                $trash = ap_nonce_url(
                    AP_Admin::url('forum-moderation.php', [
                        'action' => 'trash_post',
                        'post' => $id,
                        'view' => 'pending',
                    ]),
                    'mod-trash_post-' . $id
                );
                $html .= '<tr>'
                    . '<th class="check-column"><input type="checkbox" name="post[]" value="' . $id . '"></th>'
                    . '<td>' . ap_esc_html($excerpt) . '</td>'
                    . '<td>' . ap_esc_html($topicTitle) . '</td>'
                    . '<td>' . ap_esc_html($this->userLabel((int) $post->poster_id)) . '</td>'
                    . '<td>' . ap_esc_html((string) $post->post_time) . '</td>'
                    . '<td><a href="' . ap_esc_url($approve) . '">Approve</a> | '
                    . '<a class="submitdelete" href="' . ap_esc_url($trash) . '">Reject</a></td>'
                    . '</tr>';
            }
        }
        $html .= '</tbody></table></form>';

        return $html;
    }

    private function renderReports(): string
    {
        $nonce = ap_create_nonce('bulk-forum-moderation');
        $html = '<form method="post" action="" class="ap-list-table-form">'
            . '<input type="hidden" name="_ap_nonce" value="' . ap_esc_attr($nonce) . '">'
            . '<input type="hidden" name="view" value="reports">';
        $html .= '<div class="ap-tablenav top">'
            . $this->bulkSelect('action', [
                'report_resolve' => 'Resolve',
                'report_dismiss' => 'Dismiss',
            ])
            . '<button type="submit" class="button action">Apply</button></div>';
        $html .= '<table class="ap-list-table striped widefat"><thead><tr>'
            . '<th class="check-column"><input type="checkbox" aria-label="Select all reports"></th>'
            . '<th>Type</th><th>Object</th><th>Reason</th><th>Reporter</th><th>When</th>'
            . '<th>Status</th><th>Actions</th></tr></thead><tbody>';

        if ($this->reports === []) {
            $html .= '<tr><td colspan="8">No reports found.</td></tr>';
        } else {
            foreach ($this->reports as $report) {
                $id = (int) $report->report_id;
                $status = (string) $report->report_status;
                $actions = [];
                if ($status === AP_Forum_Moderation::REPORT_STATUS_OPEN) {
                    $actions[] = '<a href="' . ap_esc_url(ap_nonce_url(
                        AP_Admin::url('forum-moderation.php', [
                            'action' => 'resolve_report',
                            'report' => $id,
                            'view' => 'reports',
                        ]),
                        'mod-resolve_report-' . $id
                    )) . '">Resolve</a>';
                    $actions[] = '<a href="' . ap_esc_url(ap_nonce_url(
                        AP_Admin::url('forum-moderation.php', [
                            'action' => 'dismiss_report',
                            'report' => $id,
                            'view' => 'reports',
                        ]),
                        'mod-dismiss_report-' . $id
                    )) . '">Dismiss</a>';
                } else {
                    $actions[] = '<a href="' . ap_esc_url(ap_nonce_url(
                        AP_Admin::url('forum-moderation.php', [
                            'action' => 'reopen_report',
                            'report' => $id,
                            'view' => 'reports',
                        ]),
                        'mod-reopen_report-' . $id
                    )) . '">Re-open</a>';
                }
                $html .= '<tr>'
                    . '<th class="check-column"><input type="checkbox" name="report[]" value="'
                    . $id . '"></th>'
                    . '<td>' . ap_esc_html((string) $report->report_type) . '</td>'
                    . '<td>#' . (int) $report->report_object_id . '</td>'
                    . '<td>' . ap_esc_html((string) $report->report_reason) . '</td>'
                    . '<td>' . ap_esc_html($this->userLabel((int) $report->reporter_id)) . '</td>'
                    . '<td>' . ap_esc_html((string) $report->reported_at) . '</td>'
                    . '<td>' . ap_esc_html(ucfirst($status)) . '</td>'
                    . '<td>' . implode(' | ', $actions) . '</td>'
                    . '</tr>';
            }
        }
        $html .= '</tbody></table></form>';

        return $html;
    }

    /**
     * @param array<string, string> $actions
     */
    private function bulkSelect(string $name, array $actions): string
    {
        $html = '<select name="' . ap_esc_attr($name) . '">'
            . '<option value="-1">Bulk actions</option>';
        foreach ($actions as $value => $label) {
            $html .= '<option value="' . ap_esc_attr($value) . '">'
                . ap_esc_html($label) . '</option>';
        }
        $html .= '</select>';

        return $html;
    }

    private function forumName(int $forumId): string
    {
        if ($forumId < 1) {
            return '—';
        }
        $forum = AP_Forum::getForum($forumId, $this->resolveDb());

        return $forum !== null ? (string) $forum->forum_name : 'Forum #' . $forumId;
    }

    private function userLabel(int $userId): string
    {
        if ($userId < 1) {
            return 'Guest';
        }
        if (class_exists('AP_User', false)) {
            $user = AP_User::getById($userId, $this->resolveDb());
            if ($user !== null) {
                return $user->display_name !== ''
                    ? (string) $user->display_name
                    : (string) $user->user_login;
            }
        }

        return 'User #' . $userId;
    }

    public static function excerpt(string $text, int $len = 120): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text) <= $len) {
                return $text;
            }

            return mb_substr($text, 0, $len - 1) . '…';
        }
        if (strlen($text) <= $len) {
            return $text;
        }

        return substr($text, 0, $len - 1) . '…';
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
