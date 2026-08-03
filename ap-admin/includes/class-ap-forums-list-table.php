<?php

/**
 * Hierarchical forums / categories list table for admin.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Forums structure list renderer and bulk delete processor.
 */
class AP_Forums_List_Table
{
    /** @var list<object> Flattened hierarchy with depth property. */
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

        if ($this->search !== '') {
            $forums = AP_Forum::getForums([
                'include_hidden' => true,
                'search' => $this->search,
            ], $db);
            $this->items = [];
            foreach ($forums as $forum) {
                $forum->_depth = 0;
                $this->items[] = $forum;
            }
        } else {
            $tree = AP_Forum::getHierarchy(0, ['include_hidden' => true], $db);
            $this->items = [];
            $this->flattenTree($tree, 0);
        }

        $this->totalItems = count($this->items);
    }

    /**
     * @param list<array{forum: object, children: list}> $tree
     */
    private function flattenTree(array $tree, int $depth): void
    {
        foreach ($tree as $node) {
            $forum = $node['forum'];
            $forum->_depth = $depth;
            $this->items[] = $forum;
            if (!empty($node['children'])) {
                $this->flattenTree($node['children'], $depth + 1);
            }
        }
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
        if (!ap_check_nonce($nonce, 'bulk-forums', $actorId > 0 ? $actorId : null)) {
            return ['ok' => false, 'message_key' => 'nonce', 'count' => 0, 'errors' => ['Security check failed.']];
        }

        if (!AP_Admin::userCan($actorId, 'manage_forums', null, $db)) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'count' => 0,
                'errors' => ['You do not have permission to manage forums.'],
            ];
        }

        $ids = $post['forum'] ?? $post['forum_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return ['ok' => false, 'message_key' => '', 'count' => 0, 'errors' => ['No forums selected.']];
        }

        $count = 0;
        $errors = [];
        $force = $action === 'force_delete';

        if (!in_array($action, ['delete', 'force_delete'], true)) {
            return ['ok' => false, 'message_key' => 'error', 'count' => 0, 'errors' => ['Unknown bulk action.']];
        }

        // Delete deepest first so parents can succeed after children go.
        rsort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $ok = AP_Forum::deleteForum($id, $force, $db);
            if ($ok) {
                $count++;
            } else {
                $errors[] = "Could not delete forum #{$id} (remove children/topics first or force delete).";
            }
        }

        return [
            'ok' => $count > 0,
            'message_key' => $count > 0 ? 'bulk_forum_deleted' : 'error',
            'count' => $count,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getColumns(): array
    {
        return [
            'cb' => '',
            'name' => 'Name',
            'type' => 'Type',
            'status' => 'Status',
            'topics' => 'Topics',
            'posts' => 'Posts',
            'order' => 'Order',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getBulkActions(): array
    {
        return [
            'delete' => 'Delete (if empty)',
            'force_delete' => 'Force delete',
        ];
    }

    public function renderSearchBox(): string
    {
        $s = ap_esc_attr($this->search);

        return '<form method="get" action="" class="ap-search-form" role="search">'
            . '<label class="screen-reader-text" for="forum-search-input">Search forums</label>'
            . '<input type="search" id="forum-search-input" name="s" value="' . $s
            . '" placeholder="Search forums…">'
            . '<button type="submit" class="button">Search Forums</button>'
            . '</form>';
    }

    public function render(): string
    {
        $columns = $this->getColumns();
        $bulk = $this->getBulkActions();
        $nonce = ap_create_nonce('bulk-forums');

        $html = '<form method="post" action="" class="ap-list-table-form">'
            . '<input type="hidden" name="_ap_nonce" value="' . ap_esc_attr($nonce) . '">';

        $html .= '<div class="ap-tablenav top">'
            . $this->renderBulkDropdown('action', $bulk)
            . '<button type="submit" class="button action">Apply</button>'
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
            $html .= '<tr><td colspan="' . count($columns) . '">No forums found. '
                . 'Create a category or forum to get started.</td></tr>';
        } else {
            foreach ($this->items as $forum) {
                $html .= $this->renderRow($forum, $columns);
            }
        }

        $html .= '</tbody></table>';
        $html .= '<div class="ap-tablenav bottom">'
            . $this->renderBulkDropdown('action2', $bulk)
            . '<button type="submit" class="button action">Apply</button>'
            . '</div></form>';

        return $html;
    }

    /**
     * @param array<string, string> $columns
     */
    private function renderRow(object $forum, array $columns): string
    {
        $id = (int) $forum->forum_id;
        $depth = (int) ($forum->_depth ?? 0);
        $name = (string) $forum->forum_name;
        $editUrl = AP_Admin::url('forum-edit.php', ['forum' => $id]);
        $deleteUrl = ap_nonce_url(
            AP_Admin::url('forums.php', ['action' => 'delete', 'forum' => $id]),
            'delete-forum-' . $id
        );

        $row = '<tr id="forum-' . $id . '">';
        foreach ($columns as $key => $_label) {
            switch ($key) {
                case 'cb':
                    $row .= '<th scope="row" class="check-column">'
                        . '<input type="checkbox" name="forum[]" value="' . $id . '" '
                        . 'aria-label="Select ' . ap_esc_attr($name) . '">'
                        . '</th>';
                    break;
                case 'name':
                    $indent = $depth > 0
                        ? '<span class="ap-tree-indent" style="padding-left:'
                        . ($depth * 1.25) . 'rem"></span>'
                        : '';
                    $row .= '<td class="column-name" data-colname="Name">'
                        . $indent
                        . '<strong><a class="row-title" href="' . ap_esc_url($editUrl) . '">'
                        . ap_esc_html($name) . '</a></strong>'
                        . '<div class="row-actions">'
                        . '<span class="edit"><a href="' . ap_esc_url($editUrl) . '">Edit</a> | </span>'
                        . '<span class="trash"><a href="' . ap_esc_url($deleteUrl)
                        . '" class="submitdelete">Delete</a></span>'
                        . '</div></td>';
                    break;
                case 'type':
                    $row .= '<td class="column-type" data-colname="Type">'
                        . ap_esc_html(ucfirst((string) $forum->forum_type)) . '</td>';
                    break;
                case 'status':
                    $row .= '<td class="column-status" data-colname="Status">'
                        . ap_esc_html(ucfirst((string) $forum->forum_status)) . '</td>';
                    break;
                case 'topics':
                    $row .= '<td class="column-topics" data-colname="Topics">'
                        . (int) $forum->topic_count . '</td>';
                    break;
                case 'posts':
                    $row .= '<td class="column-posts" data-colname="Posts">'
                        . (int) $forum->post_count . '</td>';
                    break;
                case 'order':
                    $row .= '<td class="column-order" data-colname="Order">'
                        . (int) $forum->forum_order . '</td>';
                    break;
                default:
                    $row .= '<td></td>';
            }
        }
        $row .= '</tr>';

        return $row;
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
