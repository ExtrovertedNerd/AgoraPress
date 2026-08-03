<?php

/**
 * Admin management for forum user groups.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Forum groups list + create/update/delete helpers.
 */
class AP_Admin_Forum_Groups
{
    /** @var list<object> */
    public array $items = [];

    public int $totalItems = 0;

    public string $search = '';

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
        $this->search = trim((string) ($request['s'] ?? ''));
        $db = $this->resolveDb();
        AP_Group::ensureSystemGroups($db);

        $args = [
            'orderby' => 'name',
            'order' => 'ASC',
        ];
        if ($this->search !== '') {
            $args['search'] = $this->search;
        }
        $this->items = AP_Group::query($args, $db);
        $this->totalItems = count($this->items);
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return array{ok: bool, message_key: string, group_id: int, errors: list<string>}
     */
    public function save(array $post, int $actorId = 0): array
    {
        $db = $this->resolveDb();
        if ($actorId < 1 && function_exists('ap_get_current_user_id')) {
            $actorId = ap_get_current_user_id($db);
        }

        $id = max(0, (int) ($post['group_id'] ?? 0));
        $nonceAction = $id > 0 ? 'edit-group-' . $id : 'add-group';
        $nonce = (string) ($post['_ap_nonce'] ?? '');
        if (!ap_check_nonce($nonce, $nonceAction, $actorId > 0 ? $actorId : null)) {
            return [
                'ok' => false,
                'message_key' => 'nonce',
                'group_id' => $id,
                'errors' => ['Security check failed.'],
            ];
        }

        if (!AP_Admin::userCan($actorId, 'manage_forums', null, $db)) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'group_id' => $id,
                'errors' => ['You do not have permission to manage forum groups.'],
            ];
        }

        $name = trim((string) ($post['group_name'] ?? ''));
        if ($name === '') {
            return [
                'ok' => false,
                'message_key' => 'error',
                'group_id' => $id,
                'errors' => ['Group name is required.'],
            ];
        }

        $data = [
            'group_name' => $name,
            'group_desc' => (string) ($post['group_desc'] ?? ''),
            'group_type' => (string) ($post['group_type'] ?? AP_Group::TYPE_OPEN),
        ];
        $slug = trim((string) ($post['group_slug'] ?? ''));
        if ($slug !== '') {
            $data['group_slug'] = $slug;
        }

        if ($id > 0) {
            $existing = AP_Group::get($id, $db);
            if ($existing === null) {
                return [
                    'ok' => false,
                    'message_key' => 'not_found',
                    'group_id' => $id,
                    'errors' => ['Group not found.'],
                ];
            }
            $ok = AP_Group::update($id, $data, $db);

            return [
                'ok' => $ok,
                'message_key' => $ok ? 'group_updated' : 'error',
                'group_id' => $id,
                'errors' => $ok ? [] : ['Could not update the group.'],
            ];
        }

        $newId = AP_Group::create($data, $db);
        if ($newId < 1) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'group_id' => 0,
                'errors' => ['Could not create the group.'],
            ];
        }

        return [
            'ok' => true,
            'message_key' => 'group_created',
            'group_id' => $newId,
            'errors' => [],
        ];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{ok: bool, message_key: string, errors: list<string>}
     */
    public function delete(array $request, int $actorId = 0): array
    {
        $db = $this->resolveDb();
        if ($actorId < 1 && function_exists('ap_get_current_user_id')) {
            $actorId = ap_get_current_user_id($db);
        }

        $id = max(0, (int) ($request['group'] ?? $request['group_id'] ?? 0));
        if ($id < 1) {
            return ['ok' => false, 'message_key' => 'error', 'errors' => ['Invalid group.']];
        }

        $nonce = (string) ($request['_ap_nonce'] ?? $request['_wpnonce'] ?? '');
        if (!ap_check_nonce($nonce, 'delete-group-' . $id, $actorId > 0 ? $actorId : null)) {
            return ['ok' => false, 'message_key' => 'nonce', 'errors' => ['Security check failed.']];
        }

        if (!AP_Admin::userCan($actorId, 'manage_forums', null, $db)) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'errors' => ['You do not have permission to manage forum groups.'],
            ];
        }

        $group = AP_Group::get($id, $db);
        if ($group === null) {
            return ['ok' => false, 'message_key' => 'not_found', 'errors' => ['Group not found.']];
        }
        if ((string) $group->group_type === AP_Group::TYPE_SYSTEM) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'errors' => ['System groups cannot be deleted.'],
            ];
        }

        $ok = AP_Group::delete($id, $db);

        return [
            'ok' => $ok,
            'message_key' => $ok ? 'group_deleted' : 'error',
            'errors' => $ok ? [] : ['Could not delete the group.'],
        ];
    }

    /**
     * Add a member to a group.
     *
     * @param array<string, mixed> $post
     *
     * @return array{ok: bool, message_key: string, errors: list<string>}
     */
    public function addMember(array $post, int $actorId = 0): array
    {
        $db = $this->resolveDb();
        if ($actorId < 1 && function_exists('ap_get_current_user_id')) {
            $actorId = ap_get_current_user_id($db);
        }

        $groupId = max(0, (int) ($post['group_id'] ?? 0));
        $userId = max(0, (int) ($post['user_id'] ?? 0));
        $role = (string) ($post['member_role'] ?? AP_Group::ROLE_MEMBER);

        $nonce = (string) ($post['_ap_nonce'] ?? '');
        if (!ap_check_nonce($nonce, 'add-group-member-' . $groupId, $actorId > 0 ? $actorId : null)) {
            return ['ok' => false, 'message_key' => 'nonce', 'errors' => ['Security check failed.']];
        }
        if (!AP_Admin::userCan($actorId, 'manage_forums', null, $db)) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'errors' => ['You do not have permission to manage forum groups.'],
            ];
        }
        if ($groupId < 1 || $userId < 1) {
            return ['ok' => false, 'message_key' => 'error', 'errors' => ['Group and user are required.']];
        }

        $membershipId = AP_Group::addMember($groupId, $userId, $role, $db);
        $ok = $membershipId > 0;

        return [
            'ok' => $ok,
            'message_key' => $ok ? 'group_member_added' : 'error',
            'errors' => $ok ? [] : ['Could not add member (invalid user or guests group).'],
        ];
    }

    public function renderList(): string
    {
        $html = '<table class="ap-list-table striped widefat">'
            . '<thead><tr>'
            . '<th>Name</th><th>Slug</th><th>Type</th><th>Members</th><th>Actions</th>'
            . '</tr></thead><tbody>';

        if ($this->items === []) {
            $html .= '<tr><td colspan="5">No groups found.</td></tr>';
        } else {
            foreach ($this->items as $group) {
                $id = (int) $group->group_id;
                $isSystem = (string) $group->group_type === AP_Group::TYPE_SYSTEM;
                $editUrl = AP_Admin::url('forum-groups.php', ['action' => 'edit', 'group' => $id]);
                $actions = '<a href="' . ap_esc_url($editUrl) . '">Edit</a>';
                if (!$isSystem) {
                    $delUrl = ap_nonce_url(
                        AP_Admin::url('forum-groups.php', ['action' => 'delete', 'group' => $id]),
                        'delete-group-' . $id
                    );
                    $actions .= ' | <a class="submitdelete" href="' . ap_esc_url($delUrl) . '">Delete</a>';
                }
                $html .= '<tr>'
                    . '<td><strong><a href="' . ap_esc_url($editUrl) . '">'
                    . ap_esc_html((string) $group->group_name) . '</a></strong>'
                    . ($isSystem ? ' <span class="ap-muted">(system)</span>' : '')
                    . '</td>'
                    . '<td><code>' . ap_esc_html((string) $group->group_slug) . '</code></td>'
                    . '<td>' . ap_esc_html(ucfirst((string) $group->group_type)) . '</td>'
                    . '<td>' . (int) $group->member_count . '</td>'
                    . '<td>' . $actions . '</td>'
                    . '</tr>';
            }
        }
        $html .= '</tbody></table>';

        return $html;
    }

    public function renderForm(?object $group = null, int $actorId = 0): string
    {
        $isEdit = $group !== null;
        $id = $isEdit ? (int) $group->group_id : 0;
        $isSystem = $isEdit && (string) $group->group_type === AP_Group::TYPE_SYSTEM;
        $nonceAction = $isEdit ? 'edit-group-' . $id : 'add-group';
        $nonce = ap_create_nonce($nonceAction, $actorId > 0 ? $actorId : null);

        $name = $isEdit ? (string) $group->group_name : '';
        $slug = $isEdit ? (string) $group->group_slug : '';
        $desc = $isEdit ? (string) $group->group_desc : '';
        $type = $isEdit ? (string) $group->group_type : AP_Group::TYPE_OPEN;

        $html = '<form method="post" action="" class="ap-form ap-form--settings">'
            . '<input type="hidden" name="_ap_nonce" value="' . ap_esc_attr($nonce) . '">'
            . '<input type="hidden" name="action" value="' . ($isEdit ? 'edit-group' : 'add-group') . '">';
        if ($isEdit) {
            $html .= '<input type="hidden" name="group_id" value="' . $id . '">';
        }

        $html .= '<p class="ap-field">'
            . '<label for="group_name">Name <span class="required">*</span></label><br>'
            . '<input type="text" class="regular-text" id="group_name" name="group_name" required '
            . 'value="' . ap_esc_attr($name) . '">'
            . '</p>';

        $html .= '<p class="ap-field">'
            . '<label for="group_slug">Slug</label><br>'
            . '<input type="text" class="regular-text" id="group_slug" name="group_slug" '
            . 'value="' . ap_esc_attr($slug) . '"'
            . ($isSystem ? ' readonly' : '') . '>'
            . '</p>';

        $html .= '<p class="ap-field">'
            . '<label for="group_desc">Description</label><br>'
            . '<textarea class="large-text" id="group_desc" name="group_desc" rows="3">'
            . ap_esc_html($desc) . '</textarea>'
            . '</p>';

        if (!$isSystem) {
            $html .= '<p class="ap-field">'
                . '<label for="group_type">Type</label><br>'
                . '<select id="group_type" name="group_type">';
            foreach (
                [
                    AP_Group::TYPE_OPEN => 'Open',
                    AP_Group::TYPE_CLOSED => 'Closed',
                    AP_Group::TYPE_HIDDEN => 'Hidden',
                ] as $val => $label
            ) {
                $sel = $type === $val ? ' selected' : '';
                $html .= '<option value="' . ap_esc_attr($val) . '"' . $sel . '>'
                    . ap_esc_html($label) . '</option>';
            }
            $html .= '</select></p>';
        } else {
            $html .= '<input type="hidden" name="group_type" value="system">'
                . '<p class="ap-help">System groups cannot change type or be deleted.</p>';
        }

        $html .= '<p class="ap-submit">'
            . '<button type="submit" class="button button-primary">'
            . ($isEdit ? 'Update Group' : 'Add Group')
            . '</button>'
            . ' <a class="button" href="' . ap_esc_url(AP_Admin::url('forum-groups.php')) . '">Cancel</a>'
            . '</p></form>';

        if ($isEdit) {
            $html .= $this->renderMembersPanel($group, $actorId);
        }

        return $html;
    }

    private function renderMembersPanel(object $group, int $actorId): string
    {
        $id = (int) $group->group_id;
        $db = $this->resolveDb();
        $members = AP_Group::getMembers($id, ['limit' => 50], $db);
        $nonce = ap_create_nonce('add-group-member-' . $id, $actorId > 0 ? $actorId : null);

        $html = '<h2>Members</h2>';
        $html .= '<table class="ap-list-table striped widefat"><thead><tr>'
            . '<th>User ID</th><th>Role</th></tr></thead><tbody>';
        if ($members === []) {
            $html .= '<tr><td colspan="2">No explicit members (virtual memberships may still apply).</td></tr>';
        } else {
            foreach ($members as $m) {
                $html .= '<tr><td>' . (int) $m->user_id . ' — '
                    . ap_esc_html($this->userLabel((int) $m->user_id))
                    . '</td><td>' . ap_esc_html((string) $m->member_role) . '</td></tr>';
            }
        }
        $html .= '</tbody></table>';

        $html .= '<form method="post" action="" class="ap-form" style="margin-top:1rem">'
            . '<input type="hidden" name="_ap_nonce" value="' . ap_esc_attr($nonce) . '">'
            . '<input type="hidden" name="action" value="add-member">'
            . '<input type="hidden" name="group_id" value="' . $id . '">'
            . '<p class="ap-field">'
            . '<label for="user_id">Add user ID</label> '
            . '<input type="number" class="small-text" id="user_id" name="user_id" min="1" required> '
            . '<select name="member_role">'
            . '<option value="member">Member</option>'
            . '<option value="moderator">Moderator</option>'
            . '<option value="leader">Leader</option>'
            . '</select> '
            . '<button type="submit" class="button">Add Member</button>'
            . '</p></form>';

        return $html;
    }

    private function userLabel(int $userId): string
    {
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
