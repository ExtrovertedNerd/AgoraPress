<?php

/**
 * Admin list table for users.
 *
 * Columns, role views, search, pagination, bulk role change / delete.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Users list table renderer and bulk-action processor.
 */
class AP_Users_List_Table
{
    /** @var list<AP_User> */
    public array $items = [];

    public int $totalItems = 0;

    public int $perPage = 20;

    public int $currentPage = 1;

    public int $totalPages = 1;

    /** Active role view: all | role slug | none */
    public string $roleView = 'all';

    public string $search = '';

    public string $orderby = 'login';

    public string $order = 'ASC';

    /** @var array<string, int> role => count */
    public array $roleCounts = [];

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
        $this->roleView = $this->normalizeRoleView((string) ($request['role'] ?? 'all'));
        $this->search = trim((string) ($request['s'] ?? ''));
        $this->currentPage = max(1, (int) ($request['paged'] ?? 1));
        $this->perPage = max(1, min(100, (int) ($request['per_page'] ?? 20)));

        $orderby = strtolower((string) ($request['orderby'] ?? 'login'));
        $allowed = ['login', 'email', 'name', 'display_name', 'registered', 'id'];
        $this->orderby = in_array($orderby, $allowed, true) ? $orderby : 'login';
        $this->order = strtoupper((string) ($request['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        $db = $this->resolveDb();
        if (class_exists('AP_Roles', false)) {
            AP_Roles::ensureDefaults($db);
        }
        $this->roleCounts = AP_User::countByRole($db);
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

        $nonce = (string) ($post['_ap_nonce'] ?? '');
        if (!ap_check_nonce($nonce, 'bulk-users', $actorId > 0 ? $actorId : null)) {
            return ['ok' => false, 'message_key' => 'nonce', 'count' => 0, 'errors' => ['Security check failed.']];
        }

        $ids = $post['users'] ?? $post['user'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return ['ok' => false, 'message_key' => '', 'count' => 0, 'errors' => ['No users selected.']];
        }

        $db = $this->resolveDb();
        $count = 0;
        $errors = [];

        if ($action === 'delete') {
            if (!$this->actorCan('delete_users', $actorId, $db)) {
                return [
                    'ok' => false,
                    'message_key' => 'error',
                    'count' => 0,
                    'errors' => ['You do not have permission to delete users.'],
                ];
            }

            foreach ($ids as $id) {
                if ($actorId > 0 && $id === $actorId) {
                    $errors[] = 'You cannot delete your own account.';
                    continue;
                }
                if (AP_User::isLastAdministrator($id, $db)) {
                    $errors[] = 'Cannot delete the last administrator.';
                    continue;
                }
                if (AP_User::delete($id, $db)) {
                    $count++;
                } else {
                    $errors[] = "Could not delete user #{$id}.";
                }
            }

            return [
                'ok' => $count > 0,
                'message_key' => $count > 0 ? 'bulk_user_deleted' : 'error',
                'count' => $count,
                'errors' => $errors,
            ];
        }

        // Change role: action is "change_role" with new_role field, or "role:{slug}".
        $newRole = '';
        if ($action === 'change_role') {
            $newRole = trim((string) ($post['new_role'] ?? ''));
        } elseif (str_starts_with($action, 'role:')) {
            $newRole = substr($action, 5);
        }

        if ($newRole !== '') {
            if (!$this->actorCan('promote_users', $actorId, $db)) {
                return [
                    'ok' => false,
                    'message_key' => 'error',
                    'count' => 0,
                    'errors' => ['You do not have permission to change user roles.'],
                ];
            }

            if (!class_exists('AP_Roles', false) || !AP_Roles::roleExists($newRole, $db)) {
                return [
                    'ok' => false,
                    'message_key' => 'error',
                    'count' => 0,
                    'errors' => ['Unknown role.'],
                ];
            }

            foreach ($ids as $id) {
                $user = AP_User::getById($id, $db);
                if ($user === null) {
                    $errors[] = "User #{$id} not found.";
                    continue;
                }
                $currentRole = AP_Roles::getUserRole($id, $db);
                if (
                    $currentRole === 'administrator'
                    && $newRole !== 'administrator'
                    && AP_User::isLastAdministrator($id, $db)
                ) {
                    $errors[] = 'Cannot demote the last administrator.';
                    continue;
                }
                if (AP_Roles::setUserRole($id, $newRole, $db)) {
                    $count++;
                } else {
                    $errors[] = "Could not change role for #{$id}.";
                }
            }

            return [
                'ok' => $count > 0,
                'message_key' => $count > 0 ? 'bulk_user_role' : 'error',
                'count' => $count,
                'errors' => $errors,
            ];
        }

        return ['ok' => false, 'message_key' => '', 'count' => 0, 'errors' => ['Unknown bulk action.']];
    }

    /**
     * @return array{ok: bool, message_key: string, errors: list<string>}
     */
    public function processRowAction(array $get, int $actorId = 0): array
    {
        $action = (string) ($get['action'] ?? '');
        $userId = (int) ($get['user'] ?? $get['user_id'] ?? 0);
        $nonce = (string) ($get['_ap_nonce'] ?? $get['_wpnonce'] ?? '');

        if ($action !== 'delete' || $userId < 1) {
            return ['ok' => false, 'message_key' => '', 'errors' => ['Invalid action.']];
        }

        if (!ap_check_nonce($nonce, 'delete-user-' . $userId, $actorId > 0 ? $actorId : null)) {
            return ['ok' => false, 'message_key' => 'nonce', 'errors' => ['Security check failed.']];
        }

        $db = $this->resolveDb();
        if (!$this->actorCan('delete_users', $actorId, $db)) {
            return ['ok' => false, 'message_key' => 'error', 'errors' => ['Permission denied.']];
        }

        if ($actorId > 0 && $userId === $actorId) {
            return ['ok' => false, 'message_key' => 'error', 'errors' => ['You cannot delete your own account.']];
        }
        if (AP_User::isLastAdministrator($userId, $db)) {
            return ['ok' => false, 'message_key' => 'error', 'errors' => ['Cannot delete the last administrator.']];
        }

        if (!AP_User::delete($userId, $db)) {
            return ['ok' => false, 'message_key' => 'error', 'errors' => ['Could not delete that user.']];
        }

        return ['ok' => true, 'message_key' => 'user_deleted', 'errors' => []];
    }

    /**
     * @return array<string, string>
     */
    public function getColumns(): array
    {
        return [
            'cb' => '',
            'username' => 'Username',
            'name' => 'Name',
            'email' => 'Email',
            'role' => 'Role',
            'posts' => 'Posts',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getBulkActions(): array
    {
        $actions = [];
        if (!function_exists('ap_current_user_can') || ap_current_user_can('promote_users', null, $this->resolveDb())) {
            $actions['change_role'] = 'Change role to…';
        }
        if (!function_exists('ap_current_user_can') || ap_current_user_can('delete_users', null, $this->resolveDb())) {
            $actions['delete'] = 'Delete';
        }

        return $actions;
    }

    /**
     * @return list<array{key: string, label: string, count: int, current: bool, url: string}>
     */
    public function getViews(): array
    {
        $views = [];
        $total = 0;
        foreach ($this->roleCounts as $count) {
            $total += $count;
        }

        $defs = ['all' => ['label' => 'All', 'count' => $total]];
        $roleNames = class_exists('AP_Roles', false)
            ? AP_Roles::getRoleNames($this->resolveDb())
            : [];
        foreach ($roleNames as $slug => $name) {
            $defs[$slug] = [
                'label' => $name,
                'count' => $this->roleCounts[$slug] ?? 0,
            ];
        }
        $noneCount = $this->roleCounts[''] ?? 0;
        if ($noneCount > 0 || $this->roleView === 'none') {
            $defs['none'] = ['label' => 'No role', 'count' => $noneCount];
        }

        foreach ($defs as $key => $meta) {
            if ($key !== 'all' && $meta['count'] < 1 && $this->roleView !== $key) {
                continue;
            }
            $query = [];
            if ($key !== 'all') {
                $query['role'] = $key;
            }
            if ($this->search !== '') {
                $query['s'] = $this->search;
            }
            $views[] = [
                'key' => $key,
                'label' => $meta['label'],
                'count' => $meta['count'],
                'current' => $this->roleView === $key,
                'url' => AP_Admin::url('users.php', $query),
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
                . ap_esc_html($view['label']) . ' <span class="count">(' . (int) $view['count'] . ')</span>'
                . '</a></li>';
        }

        if ($parts === []) {
            return '';
        }

        return '<ul class="ap-subsubsub">' . implode(' | ', $parts) . '</ul>';
    }

    public function renderSearchBox(): string
    {
        $s = ap_esc_attr($this->search);
        $html = '<form class="ap-search-form" method="get" action="'
            . ap_esc_url(AP_Admin::url('users.php')) . '">';
        if ($this->roleView !== 'all') {
            $html .= '<input type="hidden" name="role" value="' . ap_esc_attr($this->roleView) . '" />';
        }
        $html .= '<label class="screen-reader-text" for="ap-user-search-input">Search users</label>';
        $html .= '<input type="search" id="ap-user-search-input" name="s" value="' . $s
            . '" placeholder="Search users…" />';
        $html .= '<button type="submit" class="button">Search</button>';
        $html .= '</form>';

        return $html;
    }

    public function render(): string
    {
        $actionUrl = ap_esc_url(AP_Admin::url('users.php'));
        $bulk = $this->getBulkActions();
        $columns = $this->getColumns();
        $actorId = function_exists('ap_get_current_user_id') ? ap_get_current_user_id($this->resolveDb()) : 0;

        $html = '<form method="post" action="' . $actionUrl . '" class="ap-list-form">';
        if ($this->roleView !== 'all') {
            $html .= '<input type="hidden" name="role" value="' . ap_esc_attr($this->roleView) . '" />';
        }
        if ($this->search !== '') {
            $html .= '<input type="hidden" name="s" value="' . ap_esc_attr($this->search) . '" />';
        }
        $html .= ap_nonce_field('bulk-users', '_ap_nonce', false, $actorId > 0 ? $actorId : null);

        $html .= '<div class="ap-tablenav ap-tablenav-top">';
        $html .= $this->renderBulkDropdown('action', $bulk);
        $html .= $this->renderPagination();
        $html .= '</div>';

        $html .= '<table class="ap-list-table striped widefat">';
        $html .= '<thead><tr>';
        foreach ($columns as $key => $label) {
            if ($key === 'cb') {
                $html .= '<th scope="col" class="check-column">'
                    . '<input type="checkbox" id="ap-cb-select-all" aria-label="Select all" /></th>';
            } else {
                $html .= '<th scope="col" class="column-' . ap_esc_attr($key) . '">'
                    . ap_esc_html($label) . '</th>';
            }
        }
        $html .= '</tr></thead><tbody>';

        if ($this->items === []) {
            $colspan = count($columns);
            $html .= '<tr class="no-items"><td colspan="' . $colspan . '">No users found.</td></tr>';
        } else {
            $db = $this->resolveDb();
            $roleNames = class_exists('AP_Roles', false) ? AP_Roles::getRoleNames($db) : [];
            foreach ($this->items as $user) {
                $html .= $this->renderRow($user, $roleNames, $actorId, $db);
            }
        }

        $html .= '</tbody></table>';

        $html .= '<div class="ap-tablenav ap-tablenav-bottom">';
        $html .= $this->renderBulkDropdown('action2', $bulk);
        $html .= $this->renderPagination();
        $html .= '</div>';
        $html .= '</form>';

        return $html;
    }

    /**
     * @param array<string, string> $bulk
     */
    private function renderBulkDropdown(string $name, array $bulk): string
    {
        if ($bulk === []) {
            return '';
        }

        $html = '<div class="alignleft actions bulkactions">';
        $html .= '<label class="screen-reader-text" for="bulk-' . ap_esc_attr($name) . '">Select bulk action</label>';
        $html .= '<select name="' . ap_esc_attr($name) . '" id="bulk-' . ap_esc_attr($name) . '">';
        $html .= '<option value="-1">Bulk actions</option>';
        foreach ($bulk as $key => $label) {
            $html .= '<option value="' . ap_esc_attr($key) . '">' . ap_esc_html($label) . '</option>';
        }
        $html .= '</select> ';

        if (isset($bulk['change_role']) && class_exists('AP_Roles', false)) {
            $html .= '<label class="screen-reader-text" for="new_role_' . ap_esc_attr($name) . '">New role</label>';
            $html .= '<select name="new_role" id="new_role_' . ap_esc_attr($name) . '">';
            $html .= '<option value="">— Role —</option>';
            foreach (AP_Roles::getRoleNames($this->resolveDb()) as $slug => $label) {
                $html .= '<option value="' . ap_esc_attr($slug) . '">' . ap_esc_html($label) . '</option>';
            }
            $html .= '</select> ';
        }

        $html .= '<button type="submit" class="button action">Apply</button>';
        $html .= '</div>';

        return $html;
    }

    private function renderPagination(): string
    {
        if ($this->totalPages <= 1) {
            return '<div class="tablenav-pages"><span class="displaying-num">'
                . (int) $this->totalItems . ' item'
                . ($this->totalItems === 1 ? '' : 's')
                . '</span></div>';
        }

        $query = [];
        if ($this->roleView !== 'all') {
            $query['role'] = $this->roleView;
        }
        if ($this->search !== '') {
            $query['s'] = $this->search;
        }

        $prev = max(1, $this->currentPage - 1);
        $next = min($this->totalPages, $this->currentPage + 1);
        $prevUrl = AP_Admin::url('users.php', $query + ['paged' => $prev]);
        $nextUrl = AP_Admin::url('users.php', $query + ['paged' => $next]);

        $html = '<div class="tablenav-pages">';
        $html .= '<span class="displaying-num">' . (int) $this->totalItems . ' items</span> ';
        $html .= '<span class="pagination-links">';
        if ($this->currentPage > 1) {
            $html .= '<a class="prev-page button" href="' . ap_esc_url($prevUrl) . '">‹</a> ';
        }
        $html .= '<span class="paging-input">' . (int) $this->currentPage
            . ' of <span class="total-pages">' . (int) $this->totalPages . '</span></span> ';
        if ($this->currentPage < $this->totalPages) {
            $html .= '<a class="next-page button" href="' . ap_esc_url($nextUrl) . '">›</a>';
        }
        $html .= '</span></div>';

        return $html;
    }

    /**
     * @param array<string, string> $roleNames
     */
    private function renderRow(AP_User $user, array $roleNames, int $actorId, AP_DB $db): string
    {
        $id = $user->ID;
        $login = $user->user_login;
        $display = $user->display_name !== '' ? $user->display_name : $login;
        $email = $user->user_email;
        $roleSlug = class_exists('AP_Roles', false) ? AP_Roles::getUserRole($id, $db) : '';
        $roleLabel = $roleSlug !== '' ? ($roleNames[$roleSlug] ?? $roleSlug) : '—';
        $posts = AP_User::countPosts($id, $db);
        $profile = AP_User::getProfileMeta($id, $db);
        $fullName = trim($profile['first_name'] . ' ' . $profile['last_name']);
        if ($fullName === '') {
            $fullName = $display;
        }

        $editUrl = AP_Admin::url('user-edit.php', ['user_id' => $id]);
        $canEdit = !function_exists('ap_current_user_can')
            || ap_current_user_can('edit_users', null, $db)
            || ($actorId === $id);
        $canDelete = (!function_exists('ap_current_user_can') || ap_current_user_can('delete_users', null, $db))
            && $id !== $actorId
            && !AP_User::isLastAdministrator($id, $db);

        $html = '<tr>';
        $html .= '<th scope="row" class="check-column">'
            . '<input type="checkbox" name="users[]" value="' . (int) $id . '" /></th>';

        $html .= '<td class="column-username">';
        if (class_exists('AP_Avatar', false)) {
            $html .= '<span class="ap-user-list-avatar">'
                . AP_Avatar::getHtml($user, 32, [
                    'class' => 'avatar avatar-32 photo',
                    'alt' => '',
                    'force_display' => true,
                ], $db)
                . '</span> ';
        }
        if ($canEdit) {
            $html .= '<strong><a class="row-title" href="' . ap_esc_url($editUrl) . '">'
                . ap_esc_html($login) . '</a></strong>';
        } else {
            $html .= '<strong class="row-title">' . ap_esc_html($login) . '</strong>';
        }
        $html .= '<div class="row-actions">';
        $actions = [];
        if ($canEdit) {
            $actions[] = '<span class="edit"><a href="' . ap_esc_url($editUrl) . '">Edit</a></span>';
        }
        if ($canDelete) {
            $delNonce = ap_create_nonce('delete-user-' . $id, $actorId > 0 ? $actorId : null);
            $delUrl = AP_Admin::url('users.php', [
                'action' => 'delete',
                'user' => $id,
                '_ap_nonce' => $delNonce,
            ]);
            $actions[] = '<span class="delete"><a class="submitdelete" href="'
                . ap_esc_url($delUrl) . '">Delete</a></span>';
        }
        $html .= implode(' | ', $actions);
        $html .= '</div></td>';

        $html .= '<td class="column-name">' . ap_esc_html($fullName) . '</td>';
        $html .= '<td class="column-email"><a href="mailto:' . ap_esc_attr($email) . '">'
            . ap_esc_html($email) . '</a></td>';
        $html .= '<td class="column-role">' . ap_esc_html($roleLabel) . '</td>';
        $html .= '<td class="column-posts">' . (int) $posts . '</td>';
        $html .= '</tr>';

        return $html;
    }

    private function loadItems(): void
    {
        $db = $this->resolveDb();
        $args = [
            'search' => $this->search,
            'orderby' => $this->orderby === 'name' ? 'display_name' : $this->orderby,
            'order' => $this->order,
            'number' => $this->perPage,
            'offset' => ($this->currentPage - 1) * $this->perPage,
        ];

        if ($this->roleView === 'none') {
            // Users with no registered role: filter in PHP after a broader fetch.
            $all = AP_User::query([
                'search' => $this->search,
                'orderby' => $args['orderby'],
                'order' => $args['order'],
                'number' => 0,
            ], $db);
            $filtered = [];
            foreach ($all as $user) {
                $role = class_exists('AP_Roles', false) ? AP_Roles::getUserRole($user->ID, $db) : '';
                if ($role === '') {
                    $filtered[] = $user;
                }
            }
            $this->totalItems = count($filtered);
            $this->totalPages = max(1, (int) ceil($this->totalItems / $this->perPage));
            $this->items = array_slice($filtered, $args['offset'], $this->perPage);

            return;
        }

        if ($this->roleView !== 'all') {
            $args['role'] = $this->roleView;
        }

        $this->totalItems = AP_User::count($args, $db);
        $this->totalPages = max(1, (int) ceil($this->totalItems / $this->perPage));
        if ($this->currentPage > $this->totalPages) {
            $this->currentPage = $this->totalPages;
            $args['offset'] = ($this->currentPage - 1) * $this->perPage;
        }
        $this->items = AP_User::query($args, $db);
    }

    private function normalizeRoleView(string $raw): string
    {
        $raw = strtolower(trim($raw));
        if ($raw === '' || $raw === 'all') {
            return 'all';
        }
        if ($raw === 'none') {
            return 'none';
        }
        $raw = preg_replace('/[^a-z0-9_\-]/', '', $raw) ?? '';
        if ($raw === '') {
            return 'all';
        }
        if (class_exists('AP_Roles', false) && !AP_Roles::roleExists($raw, $this->resolveDb())) {
            return 'all';
        }

        return $raw;
    }

    private function resolveDb(): AP_DB
    {
        if ($this->db instanceof AP_DB) {
            return $this->db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }
        throw new RuntimeException('No database connection for users list table.');
    }

    /**
     * Capability check for a known actor id (falls back to current user).
     */
    private function actorCan(string $cap, int $actorId, AP_DB $db): bool
    {
        if ($actorId > 0 && function_exists('ap_user_can')) {
            return ap_user_can($actorId, $cap, null, $db);
        }
        if (function_exists('ap_current_user_can')) {
            return ap_current_user_can($cap, null, $db);
        }

        // No roles layer — allow (structural / early bootstrap).
        return true;
    }
}
