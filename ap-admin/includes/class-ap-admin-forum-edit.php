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
            if (!$ok) {
                return [
                    'ok' => false,
                    'message_key' => 'error',
                    'forum_id' => $id,
                    'errors' => ['Could not update the forum.'],
                ];
            }

            // Forum ACL only — never applies to blog posts or pages.
            if (class_exists('AP_Forum_Permissions', false) && array_key_exists('forum_access_level', $post)) {
                if (!AP_Forum_Permissions::saveAccessFromForm($id, $post, $db)) {
                    return [
                        'ok' => false,
                        'message_key' => 'error',
                        'forum_id' => $id,
                        'errors' => ['Forum saved, but permissions could not be updated.'],
                    ];
                }
            }

            return [
                'ok' => true,
                'message_key' => 'forum_updated',
                'forum_id' => $id,
                'errors' => [],
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

        // Apply access level / custom matrix on create (defaults to public when omitted).
        if (class_exists('AP_Forum_Permissions', false)) {
            if (array_key_exists('forum_access_level', $post)) {
                AP_Forum_Permissions::saveAccessFromForm($newId, $post, $db);
            } else {
                AP_Forum_Permissions::applyAccessLevel(
                    $newId,
                    AP_Forum_Permissions::ACCESS_PUBLIC,
                    $db
                );
            }
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

        // Visibility & permissions by user level (forums only — not posts/pages).
        $html .= self::renderPermissionsFieldset($id, $db);

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

    /**
     * Access level preset + per-level permission matrix for a forum.
     *
     * Levels (increasing ability): Guest → Registered → Moderator → Administrator.
     * Does not affect blog posts or static pages.
     */
    public static function renderPermissionsFieldset(int $forumId = 0, ?AP_DB $db = null): string
    {
        if (!class_exists('AP_Forum_Permissions', false)) {
            return '';
        }

        $db = self::resolveDb($db);
        AP_Forum_Permissions::ensureDefaults($db);

        $accessLevel = $forumId > 0
            ? AP_Forum_Permissions::detectAccessLevel($forumId, $db)
            : AP_Forum_Permissions::ACCESS_PUBLIC;
        $matrix = $forumId > 0
            ? AP_Forum_Permissions::getLevelMatrix($forumId, true, $db)
            : AP_Forum_Permissions::matrixForAccessLevel(AP_Forum_Permissions::ACCESS_PUBLIC);

        $levelLabels = AP_Forum_Permissions::systemLevelLabels();
        $permLabels = AP_Forum_Permissions::permissionLabels();
        $accessLabels = AP_Forum_Permissions::accessLevelLabels();
        $accessDescs = AP_Forum_Permissions::accessLevelDescriptions();

        $html = '<fieldset class="ap-fieldset ap-forum-permissions-fieldset">'
            . '<legend>Visibility &amp; permissions</legend>'
            . '<p class="ap-help">'
            . 'Controls who can <strong>see</strong> and <strong>use</strong> this forum. '
            . 'These rules apply only to forums — blog posts and pages use publish status instead '
            . '(published is visible to everyone; drafts are not). '
            . 'Each level has increasing ability: Guest → Registered → Moderator → Administrator.'
            . '</p>';

        $html .= '<p class="ap-field">'
            . '<label for="forum_access_level"><strong>Access level</strong></label><br>'
            . '<select id="forum_access_level" name="forum_access_level" class="ap-forum-access-level">';
        foreach ($accessLabels as $slug => $label) {
            $sel = $accessLevel === $slug ? ' selected' : '';
            $html .= '<option value="' . ap_esc_attr($slug) . '"' . $sel . '>'
                . ap_esc_html($label) . '</option>';
        }
        $html .= '</select></p>';

        $html .= '<div class="ap-forum-access-desc-list" id="ap-forum-access-descs">';
        foreach ($accessDescs as $slug => $desc) {
            $hidden = $accessLevel === $slug ? '' : ' hidden';
            $html .= '<p class="ap-help ap-forum-access-desc" data-access-level="'
                . ap_esc_attr($slug) . '"' . $hidden . '>'
                . ap_esc_html($desc) . '</p>';
        }
        $html .= '</div>';

        $html .= '<p class="ap-help" style="margin-top:0.75rem;">'
            . '<strong>Custom matrix</strong> (used when Access level is “Custom”, '
            . 'or as a preview of the selected preset). '
            . 'Unchecked = denied for that level.'
            . '</p>';

        $html .= '<div class="ap-forum-perm-table-wrap">'
            . '<table class="ap-list-table striped widefat ap-forum-perm-table">'
            . '<thead><tr><th scope="col">Permission</th>';
        foreach (AP_Forum_Permissions::systemLevels() as $level) {
            $html .= '<th scope="col" class="ap-forum-perm-level">'
                . ap_esc_html($levelLabels[$level] ?? $level) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($permLabels as $perm => $label) {
            $html .= '<tr><th scope="row">' . ap_esc_html($label) . '</th>';
            foreach (AP_Forum_Permissions::systemLevels() as $level) {
                $checked = !empty($matrix[$level][$perm]) ? ' checked' : '';
                // Administrators always retain full access in the UI (locked on).
                $disabled = $level === AP_Forum_Permissions::LEVEL_ADMINISTRATOR ? ' disabled' : '';
                $name = 'forum_perm[' . $level . '][' . $perm . ']';
                $id = 'forum_perm_' . $level . '_' . $perm;
                $html .= '<td class="ap-forum-perm-cell">';
                if ($level === AP_Forum_Permissions::LEVEL_ADMINISTRATOR) {
                    // Disabled checkboxes are not submitted — send hidden allow=1.
                    $html .= '<input type="hidden" name="' . ap_esc_attr($name) . '" value="1">';
                }
                $html .= '<label class="screen-reader-text" for="' . ap_esc_attr($id) . '">'
                    . ap_esc_html(($levelLabels[$level] ?? $level) . ': ' . $label)
                    . '</label>'
                    . '<input type="checkbox" id="' . ap_esc_attr($id) . '" name="'
                    . ap_esc_attr($name) . '" value="1"' . $checked . $disabled
                    . ' data-perm="' . ap_esc_attr($perm) . '" data-level="'
                    . ap_esc_attr($level) . '">'
                    . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        // Lightweight progressive enhancement: selecting a preset fills checkboxes.
        $presetsJson = [];
        foreach (AP_Forum_Permissions::accessLevels() as $slug) {
            if ($slug === AP_Forum_Permissions::ACCESS_CUSTOM) {
                continue;
            }
            $presetsJson[$slug] = AP_Forum_Permissions::matrixForAccessLevel($slug);
        }
        $json = (string) json_encode($presetsJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        $html .= '<script>(function(){'
            . 'var sel=document.getElementById("forum_access_level");'
            . 'if(!sel)return;'
            . 'var presets=' . $json . ';'
            . 'function showDesc(level){'
            . 'var nodes=document.querySelectorAll(".ap-forum-access-desc");'
            . 'for(var i=0;i<nodes.length;i++){'
            . 'nodes[i].hidden=nodes[i].getAttribute("data-access-level")!==level;'
            . '}}'
            . 'function applyPreset(level){'
            . 'if(level==="custom"||!presets[level])return;'
            . 'var m=presets[level];'
            . 'var boxes=document.querySelectorAll(".ap-forum-perm-table input[type=checkbox][data-level]");'
            . 'for(var i=0;i<boxes.length;i++){'
            . 'var b=boxes[i],lv=b.getAttribute("data-level"),p=b.getAttribute("data-perm");'
            . 'if(b.disabled)continue;'
            . 'b.checked=!!(m[lv]&&m[lv][p]);'
            . '}}'
            . 'sel.addEventListener("change",function(){'
            . 'showDesc(sel.value);'
            . 'if(sel.value!=="custom")applyPreset(sel.value);'
            . '});'
            . 'var boxes=document.querySelectorAll(".ap-forum-perm-table input[type=checkbox]:not([disabled])");'
            . 'for(var j=0;j<boxes.length;j++){'
            . 'boxes[j].addEventListener("change",function(){'
            . 'if(sel.value!=="custom"){sel.value="custom";showDesc("custom");}'
            . '});'
            . '}'
            . 'showDesc(sel.value);'
            . '})();</script>';

        $html .= '</fieldset>';

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
