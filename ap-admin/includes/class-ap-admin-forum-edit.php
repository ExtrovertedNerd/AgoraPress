<?php

/**
 * Admin create / update / delete for forum hierarchy rows.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Forum structure edit helpers (categories, forums, links).
 */
class AP_Admin_Forum_Edit
{
    /**
     * Save a forum from a POST bag (add or edit).
     *
     * @param array<string, mixed> $post
     *
     * @return array{ok: bool, message_key: string, forum_id: int, errors: list<string>}
     */
    public static function save(array $post, int $actorId = 0, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        if ($actorId < 1 && function_exists('ap_get_current_user_id')) {
            $actorId = ap_get_current_user_id($db);
        }

        $id = max(0, (int) ($post['forum_id'] ?? 0));
        $nonceAction = $id > 0 ? 'edit-forum-' . $id : 'add-forum';
        $nonce = (string) ($post['_ap_nonce'] ?? '');
        if (!ap_check_nonce($nonce, $nonceAction, $actorId > 0 ? $actorId : null)) {
            return [
                'ok' => false,
                'message_key' => 'nonce',
                'forum_id' => $id,
                'errors' => ['Security check failed.'],
            ];
        }

        if (!AP_Admin::userCan($actorId, 'manage_forums', null, $db)) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'forum_id' => $id,
                'errors' => ['You do not have permission to manage forums.'],
            ];
        }

        $name = trim((string) ($post['forum_name'] ?? ''));
        if ($name === '') {
            return [
                'ok' => false,
                'message_key' => 'error',
                'forum_id' => $id,
                'errors' => ['Forum name is required.'],
            ];
        }

        $data = [
            'forum_name' => $name,
            'forum_desc' => (string) ($post['forum_desc'] ?? ''),
            'forum_type' => (string) ($post['forum_type'] ?? AP_Forum::FORUM_TYPE_FORUM),
            'forum_status' => (string) ($post['forum_status'] ?? AP_Forum::FORUM_STATUS_OPEN),
            'parent_id' => max(0, (int) ($post['parent_id'] ?? 0)),
            'forum_order' => (int) ($post['forum_order'] ?? 0),
        ];
        $slug = trim((string) ($post['forum_slug'] ?? ''));
        if ($slug !== '') {
            $data['forum_slug'] = $slug;
        }

        if ($id > 0) {
            $existing = AP_Forum::getForum($id, $db);
            if ($existing === null) {
                return [
                    'ok' => false,
                    'message_key' => 'not_found',
                    'forum_id' => $id,
                    'errors' => ['Forum not found.'],
                ];
            }
            $ok = AP_Forum::updateForum($id, $data, $db);

            return [
                'ok' => $ok,
                'message_key' => $ok ? 'forum_updated' : 'error',
                'forum_id' => $id,
                'errors' => $ok ? [] : ['Could not update the forum.'],
            ];
        }

        $newId = AP_Forum::insertForum($data, $db);
        if ($newId < 1) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'forum_id' => 0,
                'errors' => ['Could not create the forum.'],
            ];
        }

        return [
            'ok' => true,
            'message_key' => 'forum_created',
            'forum_id' => $newId,
            'errors' => [],
        ];
    }

    /**
     * Soft-or-force delete a forum (force only when empty or explicitly forced).
     *
     * @param array<string, mixed> $request
     *
     * @return array{ok: bool, message_key: string, errors: list<string>}
     */
    public static function delete(array $request, int $actorId = 0, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        if ($actorId < 1 && function_exists('ap_get_current_user_id')) {
            $actorId = ap_get_current_user_id($db);
        }

        $id = max(0, (int) ($request['forum'] ?? $request['forum_id'] ?? 0));
        if ($id < 1) {
            return ['ok' => false, 'message_key' => 'error', 'errors' => ['Invalid forum.']];
        }

        $nonce = (string) ($request['_ap_nonce'] ?? $request['_wpnonce'] ?? '');
        if (!ap_check_nonce($nonce, 'delete-forum-' . $id, $actorId > 0 ? $actorId : null)) {
            return ['ok' => false, 'message_key' => 'nonce', 'errors' => ['Security check failed.']];
        }

        if (!AP_Admin::userCan($actorId, 'manage_forums', null, $db)) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'errors' => ['You do not have permission to manage forums.'],
            ];
        }

        $forum = AP_Forum::getForum($id, $db);
        if ($forum === null) {
            return ['ok' => false, 'message_key' => 'not_found', 'errors' => ['Forum not found.']];
        }

        $force = !empty($request['force']) || (string) ($request['force'] ?? '') === '1';
        $ok = AP_Forum::deleteForum($id, $force, $db);
        if (!$ok) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'errors' => [
                    'Could not delete the forum. Remove child forums and topics first, or use force delete.',
                ],
            ];
        }

        return ['ok' => true, 'message_key' => 'forum_deleted', 'errors' => []];
    }

    /**
     * Parent forum select options (excluding $excludeId and its descendants).
     *
     * @return list<array{id: int, label: string, depth: int}>
     */
    public static function parentOptions(int $excludeId = 0, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $tree = AP_Forum::getHierarchy(0, ['include_hidden' => true], $db);
        $out = [];
        self::flattenParentOptions($tree, $out, 0, $excludeId);

        return $out;
    }

    /**
     * @param list<array{forum: object, children: list}> $tree
     * @param list<array{id: int, label: string, depth: int}> $out
     */
    private static function flattenParentOptions(
        array $tree,
        array &$out,
        int $depth,
        int $excludeId
    ): void {
        foreach ($tree as $node) {
            $forum = $node['forum'];
            $id = (int) $forum->forum_id;
            if ($excludeId > 0 && $id === $excludeId) {
                continue;
            }
            $prefix = $depth > 0 ? str_repeat('— ', $depth) : '';
            $out[] = [
                'id' => $id,
                'label' => $prefix . (string) $forum->forum_name,
                'depth' => $depth,
            ];
            if (!empty($node['children'])) {
                self::flattenParentOptions($node['children'], $out, $depth + 1, $excludeId);
            }
        }
    }

    /**
     * Render the add/edit form HTML.
     */
    public static function renderForm(?object $forum = null, int $actorId = 0, ?AP_DB $db = null): string
    {
        $db = self::resolveDb($db);
        $isEdit = $forum !== null;
        $id = $isEdit ? (int) $forum->forum_id : 0;
        $nonceAction = $isEdit ? 'edit-forum-' . $id : 'add-forum';
        $nonce = ap_create_nonce($nonceAction, $actorId > 0 ? $actorId : null);

        $name = $isEdit ? (string) $forum->forum_name : '';
        $slug = $isEdit ? (string) $forum->forum_slug : '';
        $desc = $isEdit ? (string) $forum->forum_desc : '';
        $type = $isEdit ? (string) $forum->forum_type : AP_Forum::FORUM_TYPE_FORUM;
        $status = $isEdit ? (string) $forum->forum_status : AP_Forum::FORUM_STATUS_OPEN;
        $parentId = $isEdit ? (int) $forum->parent_id : 0;
        $order = $isEdit ? (int) $forum->forum_order : 0;

        $parents = self::parentOptions($id, $db);

        $html = '<form method="post" action="" class="ap-form ap-form--settings ap-forum-edit-form">'
            . '<input type="hidden" name="_ap_nonce" value="' . ap_esc_attr($nonce) . '">'
            . '<input type="hidden" name="action" value="' . ($isEdit ? 'edit-forum' : 'add-forum') . '">';
        if ($isEdit) {
            $html .= '<input type="hidden" name="forum_id" value="' . $id . '">';
        }

        $html .= '<p class="ap-field">'
            . '<label for="forum_name">Name <span class="required">*</span></label><br>'
            . '<input type="text" class="regular-text" id="forum_name" name="forum_name" required '
            . 'value="' . ap_esc_attr($name) . '" maxlength="255">'
            . '</p>';

        $html .= '<p class="ap-field">'
            . '<label for="forum_slug">Slug</label><br>'
            . '<input type="text" class="regular-text" id="forum_slug" name="forum_slug" '
            . 'value="' . ap_esc_attr($slug) . '" maxlength="200">'
            . '<span class="ap-help">Leave blank to generate from the name.</span>'
            . '</p>';

        $html .= '<p class="ap-field">'
            . '<label for="forum_desc">Description</label><br>'
            . '<textarea class="large-text" id="forum_desc" name="forum_desc" rows="4">'
            . ap_esc_html($desc) . '</textarea>'
            . '</p>';

        $html .= '<p class="ap-field">'
            . '<label for="forum_type">Type</label><br>'
            . '<select id="forum_type" name="forum_type">';
        foreach (
            [
                AP_Forum::FORUM_TYPE_CATEGORY => 'Category',
                AP_Forum::FORUM_TYPE_FORUM => 'Forum',
                AP_Forum::FORUM_TYPE_LINK => 'Link',
            ] as $val => $label
        ) {
            $sel = $type === $val ? ' selected' : '';
            $html .= '<option value="' . ap_esc_attr($val) . '"' . $sel . '>'
                . ap_esc_html($label) . '</option>';
        }
        $html .= '</select></p>';

        $html .= '<p class="ap-field">'
            . '<label for="forum_status">Status</label><br>'
            . '<select id="forum_status" name="forum_status">';
        foreach (
            [
                AP_Forum::FORUM_STATUS_OPEN => 'Open',
                AP_Forum::FORUM_STATUS_CLOSED => 'Closed',
                AP_Forum::FORUM_STATUS_HIDDEN => 'Hidden',
            ] as $val => $label
        ) {
            $sel = $status === $val ? ' selected' : '';
            $html .= '<option value="' . ap_esc_attr($val) . '"' . $sel . '>'
                . ap_esc_html($label) . '</option>';
        }
        $html .= '</select></p>';

        $html .= '<p class="ap-field">'
            . '<label for="parent_id">Parent</label><br>'
            . '<select id="parent_id" name="parent_id">'
            . '<option value="0">— None (top level) —</option>';
        foreach ($parents as $opt) {
            $sel = $parentId === (int) $opt['id'] ? ' selected' : '';
            $html .= '<option value="' . (int) $opt['id'] . '"' . $sel . '>'
                . ap_esc_html($opt['label']) . '</option>';
        }
        $html .= '</select></p>';

        $html .= '<p class="ap-field">'
            . '<label for="forum_order">Order</label><br>'
            . '<input type="number" class="small-text" id="forum_order" name="forum_order" '
            . 'value="' . $order . '">'
            . '</p>';

        $html .= '<p class="ap-submit">'
            . '<button type="submit" class="button button-primary">'
            . ($isEdit ? 'Update Forum' : 'Add Forum')
            . '</button>';
        if ($isEdit) {
            $html .= ' <a class="button" href="' . ap_esc_url(AP_Admin::url('forums.php')) . '">Cancel</a>';
        }
        $html .= '</p></form>';

        return $html;
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('Database connection required.');
    }
}
