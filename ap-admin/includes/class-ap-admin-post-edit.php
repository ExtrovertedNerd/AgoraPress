<?php

/**
 * Admin create / edit screen logic for posts and pages.
 *
 * Handles form field collection, validation, insert/update, autosave,
 * revision restore, and HTML form rendering. Content uses the lightweight
 * classic editor toolbar (Markdown formatting buttons via {@see AP_Editor}).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Post/page edit form save + render.
 */
class AP_Admin_Post_Edit
{
    /**
     * Save a post from a form submission bag.
     *
     * Supports save_action=autosave (updates autosave revision only; parent
     * unchanged) in addition to draft/publish updates that create revisions.
     *
     * @param array<string, mixed> $input Typically $_POST.
     *
     * @return array{
     *   ok: bool,
     *   id: int,
     *   message_key: string,
     *   errors: list<string>,
     *   post: ?AP_Post,
     *   revision_id?: int
     * }
     */
    public static function save(array $input, int $userId, ?AP_DB $db = null): array
    {
        AP_Post::ensureBuiltins();
        $db = $db ?? ap_db();

        $id = (int) ($input['post_ID'] ?? $input['ID'] ?? 0);
        $isNew = $id < 1;
        $postType = AP_Admin::resolvePostType(
            (string) ($input['post_type'] ?? 'post'),
            'post'
        );

        $nonceAction = $isNew ? 'new-post' : 'update-post-' . $id;
        $nonce = (string) ($input['_ap_nonce'] ?? '');
        if (!ap_check_nonce($nonce, $nonceAction, $userId > 0 ? $userId : null)) {
            return [
                'ok' => false,
                'id' => $id,
                'message_key' => 'nonce',
                'errors' => ['Security check failed. Please reload and try again.'],
                'post' => $id > 0 ? AP_Post::get($id, $db) : null,
            ];
        }

        // Capability: create needs edit_posts/pages; update needs edit_post/page meta.
        if ($isNew) {
            $listCap = AP_Admin::editCapabilityForPostType($postType);
            if (!AP_Admin::userCan($userId, $listCap, null, $db)) {
                return [
                    'ok' => false,
                    'id' => 0,
                    'message_key' => 'error',
                    'errors' => ['You do not have permission to create this content.'],
                    'post' => null,
                ];
            }
        } else {
            $metaCap = AP_Admin::editMetaCapForPostType($postType);
            if (!AP_Admin::userCan($userId, $metaCap, $id, $db)) {
                return [
                    'ok' => false,
                    'id' => $id,
                    'message_key' => 'error',
                    'errors' => ['You do not have permission to edit this item.'],
                    'post' => AP_Post::get($id, $db),
                ];
            }
        }

        $title = ap_sanitize_text_field((string) ($input['post_title'] ?? ''));
        $content = (string) ($input['post_content'] ?? '');
        // Content allows limited HTML later; store as-is for now (escaped on output).
        $content = str_replace("\0", '', $content);
        $excerpt = ap_sanitize_textarea_field((string) ($input['post_excerpt'] ?? ''));
        $slug = ap_sanitize_text_field((string) ($input['post_name'] ?? ''));
        $status = self::normalizeStatus((string) ($input['post_status'] ?? 'draft'));
        $password = (string) ($input['post_password'] ?? '');
        $commentStatus = !empty($input['comment_status']) ? 'open' : 'closed';
        $parent = (int) ($input['post_parent'] ?? 0);
        $menuOrder = (int) ($input['menu_order'] ?? 0);
        $pageTemplate = ap_sanitize_text_field((string) ($input['page_template'] ?? 'default'));
        $sticky = !empty($input['sticky']);
        // Pages: “Show in navigation” (form always posts the field via hidden+checkbox).
        $showInNav = null;
        if ($postType === 'page' && array_key_exists('show_in_nav', $input)) {
            $raw = $input['show_in_nav'];
            // Support last-wins checkbox (scalar) or accidental multi-value arrays.
            if (is_array($raw)) {
                $raw = end($raw);
            }
            $showInNav = $raw === true || $raw === 1 || $raw === '1' || $raw === 'on';
        }

        // Publish box button overrides.
        $submit = (string) ($input['save_action'] ?? $input['original_publish'] ?? '');
        if ($submit === 'draft' || $submit === 'save-draft') {
            $status = 'draft';
        } elseif ($submit === 'publish') {
            $status = 'publish';
        } elseif ($submit === 'pending') {
            $status = 'pending';
        }

        // Autosave: snapshot only — never creates a new parent, never publishes.
        if ($submit === 'autosave') {
            return self::saveAutosave($input, $userId, $title, $content, $excerpt, $db);
        }

        // Visibility radios.
        $visibility = (string) ($input['visibility'] ?? '');
        if ($visibility === 'private') {
            $status = 'private';
            $password = '';
        } elseif ($visibility === 'password') {
            if ($status === 'private') {
                $status = 'publish';
            }
            if ($password === '' && $id > 0) {
                $existingForPw = AP_Post::get($id, $db);
                if ($existingForPw !== null) {
                    $password = $existingForPw->post_password;
                }
            }
        } elseif ($visibility === 'public') {
            if ($status === 'private') {
                $status = 'publish';
            }
            $password = '';
        } else {
            // No visibility field: honor password only when non-empty.
            if ($password === '' && $status !== 'private') {
                // leave as-is
            }
        }

        $errors = [];
        // Title may be empty (WP allows it); soft warning only.

        // Publishing (or private/future) requires publish_* unless already that status.
        $publicish = in_array($status, ['publish', 'private', 'future'], true);
        if ($publicish) {
            $needsPublishCheck = $isNew;
            if (!$isNew) {
                $existingForStatus = AP_Post::get($id, $db);
                $prev = $existingForStatus !== null ? (string) $existingForStatus->post_status : '';
                $needsPublishCheck = !in_array($prev, ['publish', 'private', 'future'], true)
                    || $prev !== $status;
            }
            if ($needsPublishCheck) {
                $pubCap = AP_Admin::publishCapabilityForPostType($postType);
                if (!AP_Admin::userCan($userId, $pubCap, null, $db)) {
                    // Contributors: fall back to pending rather than hard-fail.
                    $status = 'pending';
                }
            }
        }

        $data = [
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status' => $status,
            'post_type' => $postType,
            'post_password' => $password,
            'comment_status' => $commentStatus,
            'post_author' => $userId > 0
                ? $userId
                : (int) ($input['post_author'] ?? 0),
        ];

        if ($slug !== '') {
            $data['post_name'] = $slug;
        }

        if (AP_Post::typeIsHierarchical($postType)) {
            $data['post_parent'] = max(0, $parent);
            $data['menu_order'] = $menuOrder;
            $data['page_template'] = $pageTemplate !== '' ? $pageTemplate : 'default';
        }

        if ($postType === 'post') {
            $data['sticky'] = $sticky;
        }

        if ($postType === 'page' && $showInNav !== null) {
            $data['show_in_nav'] = $showInNav;
        }

        // Scheduled date.
        if (!empty($input['post_date']) && is_string($input['post_date'])) {
            $date = trim($input['post_date']);
            if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $date) === 1) {
                if (strlen($date) === 10) {
                    $date .= ' 00:00:00';
                } elseif (strlen($date) === 16) {
                    $date .= ':00';
                }
                $data['post_date'] = str_replace('T', ' ', $date);
            }
        }

        if ($isNew) {
            $newId = AP_Post::insert($data, $db);
            if ($newId < 1) {
                return [
                    'ok' => false,
                    'id' => 0,
                    'message_key' => 'error',
                    'errors' => ['Could not create the post. Check parent/status and try again.'],
                    'post' => null,
                ];
            }

            self::savePostTaxonomies($newId, $postType, $input, $db);

            return [
                'ok' => true,
                'id' => $newId,
                'message_key' => 'created',
                'errors' => $errors,
                'post' => AP_Post::get($newId, $db),
            ];
        }

        $existing = AP_Post::get($id, $db);
        if ($existing === null || $existing->post_type !== $postType) {
            return [
                'ok' => false,
                'id' => $id,
                'message_key' => 'not_found',
                'errors' => ['That item could not be found.'],
                'post' => null,
            ];
        }

        // Keep original author unless elevating empty.
        if ($existing->post_author > 0) {
            unset($data['post_author']);
        }

        $ok = AP_Post::update($id, $data, $db);
        if (!$ok) {
            return [
                'ok' => false,
                'id' => $id,
                'message_key' => 'error',
                'errors' => ['Could not update the post. Invalid parent or status?'],
                'post' => $existing,
            ];
        }

        self::savePostTaxonomies($id, $postType, $input, $db);

        // Clear this author's autosave after a successful real save (content is now live).
        if (AP_Post::typeSupports($postType, 'revisions')) {
            $autosave = AP_Post::getAutosave($id, $userId > 0 ? $userId : 0, $db);
            if ($autosave !== null) {
                AP_Post::deleteRevision($autosave->ID, $db);
            }
        }

        return [
            'ok' => true,
            'id' => $id,
            'message_key' => 'updated',
            'errors' => $errors,
            'post' => AP_Post::get($id, $db),
        ];
    }

    /**
     * Persist categories / tags (and other object taxonomies) from edit form input.
     *
     * @param array<string, mixed> $input
     */
    private static function savePostTaxonomies(
        int $postId,
        string $postType,
        array $input,
        AP_DB $db
    ): void {
        if ($postId < 1 || !class_exists('AP_Taxonomy', false)) {
            return;
        }

        AP_Taxonomy::ensureBuiltins();
        $objectTaxonomies = AP_Taxonomy::getObjectTaxonomies($postType);

        if (in_array('category', $objectTaxonomies, true)) {
            $cats = $input['post_category'] ?? [];
            if (!is_array($cats)) {
                $cats = $cats !== '' && $cats !== null ? [(int) $cats] : [];
            }
            $catIds = array_values(array_filter(array_map('intval', $cats), static fn (int $id): bool => $id > 0));
            AP_Taxonomy::ensureDefaultCategory($db);
            AP_Taxonomy::setObjectTerms($postId, $catIds, 'category', false, $db);
        }

        if (in_array('post_tag', $objectTaxonomies, true)) {
            $taxInput = $input['tax_input'] ?? [];
            $raw = '';
            if (is_array($taxInput) && isset($taxInput['post_tag'])) {
                $raw = (string) $taxInput['post_tag'];
            } elseif (isset($input['tags_input'])) {
                $raw = (string) $input['tags_input'];
            }
            $names = array_values(array_filter(array_map(
                static fn (string $s): string => trim($s),
                preg_split('/\s*,\s*/', $raw) ?: []
            )));
            AP_Taxonomy::setObjectTerms($postId, $names, 'post_tag', false, $db);
        }
    }

    /**
     * Autosave path: write title/content/excerpt into the author's autosave revision.
     *
     * @param array<string, mixed> $input
     *
     * @return array{
     *   ok: bool,
     *   id: int,
     *   message_key: string,
     *   errors: list<string>,
     *   post: ?AP_Post,
     *   revision_id?: int
     * }
     */
    private static function saveAutosave(
        array $input,
        int $userId,
        string $title,
        string $content,
        string $excerpt,
        AP_DB $db
    ): array {
        $id = (int) ($input['post_ID'] ?? $input['ID'] ?? 0);
        if ($id < 1) {
            return [
                'ok' => false,
                'id' => 0,
                'message_key' => 'error',
                'errors' => ['Save the post once before autosave can run.'],
                'post' => null,
            ];
        }

        $existing = AP_Post::get($id, $db);
        if ($existing === null) {
            return [
                'ok' => false,
                'id' => $id,
                'message_key' => 'not_found',
                'errors' => ['That item could not be found.'],
                'post' => null,
            ];
        }

        $metaCap = AP_Admin::editMetaCapForPostType($existing->post_type);
        if (!AP_Admin::userCan($userId, $metaCap, $id, $db)) {
            return [
                'ok' => false,
                'id' => $id,
                'message_key' => 'error',
                'errors' => ['You do not have permission to edit this item.'],
                'post' => $existing,
            ];
        }

        if (!AP_Post::typeSupports($existing->post_type, 'revisions')) {
            return [
                'ok' => false,
                'id' => $id,
                'message_key' => 'error',
                'errors' => ['This content type does not support autosave.'],
                'post' => $existing,
            ];
        }

        $revisionId = AP_Post::autosave($id, [
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
        ], $userId > 0 ? $userId : $existing->post_author, $db);

        if ($revisionId < 1) {
            // Unchanged content is not an error — report as success with no-op.
            return [
                'ok' => true,
                'id' => $id,
                'message_key' => 'autosaved',
                'errors' => [],
                'post' => $existing,
                'revision_id' => 0,
            ];
        }

        return [
            'ok' => true,
            'id' => $id,
            'message_key' => 'autosaved',
            'errors' => [],
            'post' => $existing,
            'revision_id' => $revisionId,
        ];
    }

    /**
     * Restore a revision onto its parent (nonce: restore-revision-{id}).
     *
     * @param array<string, mixed> $request
     *
     * @return array{ok: bool, message_key: string, errors: list<string>, parent_id: int}
     */
    public static function processRestoreRevision(array $request, ?AP_DB $db = null, int $actorId = 0): array
    {
        $revisionId = (int) ($request['revision'] ?? $request['revision_id'] ?? 0);
        if ($revisionId < 1) {
            return ['ok' => false, 'message_key' => '', 'errors' => [], 'parent_id' => 0];
        }

        $db = $db ?? ap_db();
        if ($actorId < 1 && function_exists('ap_get_current_user_id')) {
            $actorId = ap_get_current_user_id($db);
        }

        $nonce = (string) ($request['_ap_nonce'] ?? '');
        if (!ap_check_nonce($nonce, 'restore-revision-' . $revisionId, $actorId > 0 ? $actorId : null)) {
            return [
                'ok' => false,
                'message_key' => 'nonce',
                'errors' => ['Security check failed.'],
                'parent_id' => 0,
            ];
        }

        $revision = AP_Post::get($revisionId, $db);
        if ($revision === null || $revision->post_type !== 'revision') {
            return [
                'ok' => false,
                'message_key' => 'not_found',
                'errors' => ['Revision not found.'],
                'parent_id' => 0,
            ];
        }

        $parentId = $revision->post_parent;
        $parent = $parentId > 0 ? AP_Post::get($parentId, $db) : null;
        if ($parent !== null) {
            $metaCap = AP_Admin::editMetaCapForPostType($parent->post_type);
            if (!AP_Admin::userCan($actorId, $metaCap, $parentId, $db)) {
                return [
                    'ok' => false,
                    'message_key' => 'error',
                    'errors' => ['You do not have permission to restore revisions for this item.'],
                    'parent_id' => $parentId,
                ];
            }
        }

        $ok = AP_Post::restoreRevision($revisionId, $db);
        if (!$ok) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'errors' => ['Could not restore that revision.'],
                'parent_id' => $parentId,
            ];
        }

        return [
            'ok' => true,
            'message_key' => 'revision_restored',
            'errors' => [],
            'parent_id' => $parentId,
        ];
    }

    /**
     * Delete a single revision (nonce: delete-revision-{id}).
     *
     * @param array<string, mixed> $request
     *
     * @return array{ok: bool, message_key: string, errors: list<string>, parent_id: int}
     */
    public static function processDeleteRevision(array $request, ?AP_DB $db = null, int $actorId = 0): array
    {
        $revisionId = (int) ($request['revision'] ?? $request['revision_id'] ?? 0);
        if ($revisionId < 1) {
            return ['ok' => false, 'message_key' => '', 'errors' => [], 'parent_id' => 0];
        }

        $db = $db ?? ap_db();
        if ($actorId < 1 && function_exists('ap_get_current_user_id')) {
            $actorId = ap_get_current_user_id($db);
        }

        $nonce = (string) ($request['_ap_nonce'] ?? '');
        if (!ap_check_nonce($nonce, 'delete-revision-' . $revisionId, $actorId > 0 ? $actorId : null)) {
            return [
                'ok' => false,
                'message_key' => 'nonce',
                'errors' => ['Security check failed.'],
                'parent_id' => 0,
            ];
        }

        $revision = AP_Post::get($revisionId, $db);
        if ($revision === null || $revision->post_type !== 'revision') {
            return [
                'ok' => false,
                'message_key' => 'not_found',
                'errors' => ['Revision not found.'],
                'parent_id' => 0,
            ];
        }

        $parentId = $revision->post_parent;
        $parent = $parentId > 0 ? AP_Post::get($parentId, $db) : null;
        if ($parent !== null) {
            $metaCap = AP_Admin::editMetaCapForPostType($parent->post_type);
            if (!AP_Admin::userCan($actorId, $metaCap, $parentId, $db)) {
                return [
                    'ok' => false,
                    'message_key' => 'error',
                    'errors' => ['You do not have permission to delete revisions for this item.'],
                    'parent_id' => $parentId,
                ];
            }
        }

        $ok = AP_Post::deleteRevision($revisionId, $db);
        if (!$ok) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'errors' => ['Could not delete that revision.'],
                'parent_id' => $parentId,
            ];
        }

        return [
            'ok' => true,
            'message_key' => 'revision_deleted',
            'errors' => [],
            'parent_id' => $parentId,
        ];
    }

    /**
     * Single-item trash / untrash / delete via GET row action.
     *
     * @param array<string, mixed> $request
     *
     * @return array{ok: bool, message_key: string, errors: list<string>}
     */
    public static function processRowAction(array $request, ?AP_DB $db = null, int $actorId = 0): array
    {
        $action = (string) ($request['action'] ?? '');
        $id = (int) ($request['post'] ?? 0);
        $allowed = ['trash', 'untrash', 'delete'];
        if (!in_array($action, $allowed, true) || $id < 1) {
            return ['ok' => false, 'message_key' => '', 'errors' => []];
        }

        $db = $db ?? ap_db();
        if ($actorId < 1 && function_exists('ap_get_current_user_id')) {
            $actorId = ap_get_current_user_id($db);
        }

        $nonce = (string) ($request['_ap_nonce'] ?? '');
        if (!ap_check_nonce($nonce, 'post-row-' . $id, $actorId > 0 ? $actorId : null)) {
            return ['ok' => false, 'message_key' => 'nonce', 'errors' => ['Security check failed.']];
        }

        $post = AP_Post::get($id, $db);
        if ($post === null) {
            return ['ok' => false, 'message_key' => 'not_found', 'errors' => ['Not found.']];
        }

        $deleteCap = AP_Admin::deleteMetaCapForPostType($post->post_type);
        $editCap = AP_Admin::editMetaCapForPostType($post->post_type);
        $needed = $action === 'delete' || $action === 'trash' ? $deleteCap : $editCap;
        if (!AP_Admin::userCan($actorId, $needed, $id, $db)) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'errors' => ['You do not have permission to modify this item.'],
            ];
        }

        $ok = match ($action) {
            'trash' => AP_Post::trash($id, $db),
            'untrash' => AP_Post::untrash($id, $db),
            'delete' => AP_Post::delete($id, true, $db),
            default => false,
        };

        if (!$ok) {
            return ['ok' => false, 'message_key' => 'error', 'errors' => ['Action failed.']];
        }

        $key = match ($action) {
            'trash' => 'trashed',
            'untrash' => 'untrashed',
            'delete' => 'deleted',
            default => 'updated',
        };

        return ['ok' => true, 'message_key' => $key, 'errors' => []];
    }

    /**
     * Render the edit form HTML.
     *
     * @param array<string, mixed> $extra Optional extras (parent options already built, etc.).
     */
    public static function renderForm(
        ?AP_Post $post,
        string $postType,
        int $userId = 0,
        ?AP_DB $db = null
    ): string {
        AP_Post::ensureBuiltins();
        $db = $db ?? ($post !== null ? null : null);
        $db = $db ?? (function_exists('ap_db') ? ap_db() : null);

        $isNew = $post === null || $post->ID < 1;
        $id = $isNew ? 0 : (int) $post->ID;
        $typeLabel = AP_Admin::postTypeLabel($postType, true);
        $title = $isNew ? '' : $post->post_title;
        $content = $isNew ? '' : $post->post_content;
        $excerpt = $isNew ? '' : $post->post_excerpt;
        $slug = $isNew ? '' : $post->post_name;
        $status = $isNew ? 'draft' : $post->post_status;
        $password = $isNew ? '' : $post->post_password;
        $commentStatus = $isNew
            ? ($postType === 'page' ? 'closed' : 'open')
            : $post->comment_status;
        $parent = $isNew ? 0 : $post->post_parent;
        $menuOrder = $isNew ? 0 : $post->menu_order;
        $pageTemplate = 'default';
        $sticky = false;
        $showInNav = true;
        if (!$isNew && $db instanceof AP_DB) {
            $pageTemplate = AP_Post::getPageTemplate($id, $db);
            $sticky = AP_Post::getMeta($id, AP_Post::STICKY_META, true, $db) === '1';
            $showInNav = AP_Post::showsInNav($id, $db);
        } elseif (!$isNew) {
            $pageTemplate = AP_Post::getPageTemplate($id);
            $sticky = $post->isSticky();
            $showInNav = AP_Post::showsInNav($id);
        }

        $visibility = 'public';
        if ($status === 'private') {
            $visibility = 'private';
        } elseif ($password !== '') {
            $visibility = 'password';
        }

        $nonceAction = $isNew ? 'new-post' : 'update-post-' . $id;
        $formAction = $isNew
            ? AP_Admin::url('post-new.php', ['post_type' => $postType])
            : AP_Admin::url('post.php', ['post' => $id, 'action' => 'edit']);

        $html = '<form method="post" action="' . ap_esc_url($formAction)
            . '" id="ap-post-edit-form" class="ap-post-edit-form">';
        $html .= ap_nonce_field($nonceAction, '_ap_nonce', true, $userId > 0 ? $userId : null);
        $html .= '<input type="hidden" name="post_ID" value="' . $id . '" />';
        $html .= '<input type="hidden" name="post_type" value="' . ap_esc_attr($postType) . '" />';

        $html .= '<div class="ap-edit-layout">';
        $html .= '<div class="ap-edit-main">';

        // Title
        $html .= '<div class="ap-field ap-field-title">';
        $html .= '<label for="title" class="screen-reader-text">Title</label>';
        $html .= '<input type="text" name="post_title" id="title" size="30" value="'
            . ap_esc_attr($title) . '" placeholder="Add title" autofocus />';
        $html .= '</div>';

        // Slug
        $html .= '<div class="ap-field ap-field-slug">';
        $html .= '<label for="post_name">Slug</label> ';
        $html .= '<input type="text" name="post_name" id="post_name" value="'
            . ap_esc_attr($slug) . '" class="regular-text" />';
        if (
            !$isNew
            && $post instanceof AP_Post
            && function_exists('ap_get_permalink')
            && class_exists('AP_Rewrite', false)
        ) {
            $permalink = ap_get_permalink($post);
            if ($permalink !== '') {
                $html .= ' <span class="ap-permalink-hint">Permalink: <a href="'
                    . ap_esc_url($permalink) . '"><code>'
                    . ap_esc_html($permalink) . '</code></a></span>';
            } elseif ($slug !== '') {
                $html .= ' <span class="ap-permalink-hint">Permalink slug: <code>'
                    . ap_esc_html($slug) . '</code></span>';
            }
        } elseif (!$isNew && $slug !== '') {
            $html .= ' <span class="ap-permalink-hint">Permalink slug: <code>'
                . ap_esc_html($slug) . '</code></span>';
        }
        $html .= '</div>';

        // Content — visual WYSIWYG editor (formatted preview while editing).
        $html .= '<div class="ap-field ap-field-content">';
        $editorMode = class_exists('AP_Editor', false)
            ? AP_Editor::modeForContext($postType === 'page' ? 'page' : 'post')
            : 'visual';
        if (class_exists('AP_Editor', false)) {
            $html .= AP_Editor::render([
                'id' => 'content',
                'name' => 'post_content',
                'value' => $content,
                'mode' => $editorMode,
                'rows' => 16,
                'class' => 'large-text',
                'label' => 'Content',
                'description' => 'Visual editor: formatting appears as you type, matching the '
                    . 'published look. Toolbar buttons apply bold, lists, headings, links, and more.',
                'wrap_class' => 'ap-editor--admin',
            ]);
        } else {
            $html .= '<label for="content">Content</label>';
            $html .= '<textarea name="post_content" id="content" rows="16" class="large-text">'
                . ap_esc_textarea($content) . '</textarea>';
        }
        $html .= '</div>';

        // Excerpt (posts primarily; optional for pages)
        if (AP_Post::typeSupports($postType, 'excerpt') || $postType === 'post') {
            $html .= '<div class="ap-field ap-field-excerpt ap-metabox">';
            $html .= '<h3 class="ap-metabox-title">Excerpt</h3>';
            $html .= '<textarea name="post_excerpt" id="excerpt" rows="3" class="large-text">'
                . ap_esc_textarea($excerpt) . '</textarea>';
            $html .= '</div>';
        }

        $html .= '</div>'; // main

        // Sidebar
        $html .= '<div class="ap-edit-sidebar">';

        // Publish box
        $html .= '<div class="ap-metabox ap-metabox-publish">';
        $html .= '<h3 class="ap-metabox-title">Publish</h3>';
        $html .= '<div class="ap-metabox-body">';
        $html .= '<p><label for="post_status">Status</label><br />';
        $html .= self::renderStatusSelect($status);
        $html .= '</p>';

        $html .= '<p><strong>Visibility</strong><br />';
        $html .= '<label><input type="radio" name="visibility" value="public"'
            . ($visibility === 'public' ? ' checked' : '') . ' /> Public</label><br />';
        $html .= '<label><input type="radio" name="visibility" value="password"'
            . ($visibility === 'password' ? ' checked' : '') . ' /> Password protected</label><br />';
        $html .= '<label><input type="radio" name="visibility" value="private"'
            . ($visibility === 'private' ? ' checked' : '') . ' /> Private</label>';
        $html .= '</p>';
        $html .= '<p><label for="post_password">Password</label><br />';
        $html .= '<input type="text" name="post_password" id="post_password" value="'
            . ap_esc_attr($password) . '" class="regular-text" autocomplete="off" />';
        $html .= '</p>';

        $html .= '<p><label for="post_date">Publish date</label><br />';
        $dateVal = $isNew ? '' : ($post->post_date ?? '');
        $html .= '<input type="text" name="post_date" id="post_date" value="'
            . ap_esc_attr($dateVal) . '" placeholder="YYYY-MM-DD HH:MM:SS" class="regular-text" />';
        $html .= '</p>';

        $html .= '<div class="ap-publish-actions">';
        if (!$isNew && $status !== 'trash') {
            $trashUrl = AP_Admin::url('edit.php', [
                'post_type' => $postType,
                'action' => 'trash',
                'post' => $id,
                '_ap_nonce' => ap_create_nonce('post-row-' . $id, $userId > 0 ? $userId : null),
            ]);
            $html .= '<a class="submitdelete" href="' . ap_esc_url($trashUrl) . '">Move to Trash</a> ';
        }
        $html .= '<button type="submit" name="save_action" value="draft" class="button">'
            . 'Save Draft</button> ';
        if (!$isNew && AP_Post::typeSupports($postType, 'revisions')) {
            $html .= '<button type="submit" name="save_action" value="autosave" class="button">'
                . 'Autosave</button> ';
        }
        $primaryLabel = ($status === 'publish' && !$isNew) ? 'Update' : 'Publish';
        $html .= '<button type="submit" name="save_action" value="publish" '
            . 'class="button button-primary">' . $primaryLabel . '</button>';
        $html .= '</div>';
        $html .= '</div></div>';

        // Revisions metabox
        if (!$isNew && AP_Post::typeSupports($postType, 'revisions') && $db instanceof AP_DB) {
            $html .= self::renderRevisionsMetabox($id, $postType, $userId, $db);
        }

        // Page attributes
        if (AP_Post::typeIsHierarchical($postType)) {
            $html .= '<div class="ap-metabox">';
            $html .= '<h3 class="ap-metabox-title">Page Attributes</h3>';
            $html .= '<div class="ap-metabox-body">';
            $html .= '<p><label for="post_parent">Parent</label><br />';
            $html .= self::renderParentSelect($postType, $parent, $id, $db);
            $html .= '</p>';
            $html .= '<p><label for="menu_order">Order</label><br />';
            $html .= '<input type="number" name="menu_order" id="menu_order" value="'
                . (int) $menuOrder . '" class="small-text" />';
            $html .= '</p>';
            $html .= '<p><label for="page_template">Template</label><br />';
            $html .= '<select name="page_template" id="page_template">';
            $html .= '<option value="default"' . ($pageTemplate === 'default' ? ' selected' : '')
                . '>Default template</option>';
            // Theme templates land later; free-form slug allowed for now.
            if ($pageTemplate !== 'default' && $pageTemplate !== '') {
                $html .= '<option value="' . ap_esc_attr($pageTemplate) . '" selected>'
                    . ap_esc_html($pageTemplate) . '</option>';
            }
            $html .= '</select></p>';
            if ($postType === 'page') {
                // Hidden + checkbox so an unchecked state still posts a value.
                $html .= '<p class="ap-field-show-in-nav">';
                $html .= '<input type="hidden" name="show_in_nav" value="0" />';
                $html .= '<label for="show_in_nav">'
                    . '<input type="checkbox" name="show_in_nav" id="show_in_nav" value="1"'
                    . ($showInNav ? ' checked' : '') . ' /> '
                    . 'Show in navigation</label>';
                $html .= '<br /><span class="description">Include this page in the automatic '
                    . 'primary navigation (when no custom menu is assigned), the Pages widget, '
                    . 'and the Pages list under Appearance → Menus. Uncheck to keep the page '
                    . 'published but out of those lists.</span>';
                $html .= '</p>';
            }
            $html .= '</div></div>';
        }

        // Discussion
        if (AP_Post::typeSupports($postType, 'comments') || $postType === 'post') {
            $html .= '<div class="ap-metabox">';
            $html .= '<h3 class="ap-metabox-title">Discussion</h3>';
            $html .= '<div class="ap-metabox-body">';
            $html .= '<label><input type="checkbox" name="comment_status" value="open"'
                . ($commentStatus === 'open' ? ' checked' : '') . ' /> Allow comments</label>';
            $html .= '</div></div>';
        }

        // Categories (posts + other types that register category)
        if (
            class_exists('AP_Taxonomy', false)
            && class_exists('AP_Admin_Terms', false)
            && in_array('category', AP_Taxonomy::getObjectTaxonomies($postType), true)
        ) {
            $selectedCats = [];
            if (!$isNew && $db instanceof AP_DB) {
                $selectedCats = AP_Taxonomy::getObjectTerms($id, 'category', ['fields' => 'ids'], $db);
                /** @var list<int> $selectedCats */
            }
            $html .= '<div class="ap-metabox ap-metabox-categories">';
            $html .= '<h3 class="ap-metabox-title">Categories</h3>';
            $html .= '<div class="ap-metabox-body">';
            $html .= AP_Admin_Terms::renderCategoryChecklist($selectedCats, $db instanceof AP_DB ? $db : null);
            $html .= '<p class="description"><a href="'
                . ap_esc_url(AP_Admin::url('edit-tags.php', ['taxonomy' => 'category']))
                . '">Manage categories</a></p>';
            $html .= '</div></div>';
        }

        // Tags
        if (
            class_exists('AP_Taxonomy', false)
            && class_exists('AP_Admin_Terms', false)
            && in_array('post_tag', AP_Taxonomy::getObjectTaxonomies($postType), true)
        ) {
            $tagObjs = [];
            if (!$isNew && $db instanceof AP_DB) {
                $tagObjs = AP_Taxonomy::getObjectTerms($id, 'post_tag', ['fields' => 'all'], $db);
            }
            $html .= '<div class="ap-metabox ap-metabox-tags">';
            $html .= '<h3 class="ap-metabox-title">Tags</h3>';
            $html .= '<div class="ap-metabox-body">';
            $html .= AP_Admin_Terms::renderTagsInput(is_array($tagObjs) ? $tagObjs : []);
            $html .= '<p class="description"><a href="'
                . ap_esc_url(AP_Admin::url('edit-tags.php', ['taxonomy' => 'post_tag']))
                . '">Manage tags</a></p>';
            $html .= '</div></div>';
        }

        // Sticky (posts)
        if ($postType === 'post') {
            $html .= '<div class="ap-metabox">';
            $html .= '<h3 class="ap-metabox-title">Sticky</h3>';
            $html .= '<div class="ap-metabox-body">';
            $html .= '<label><input type="checkbox" name="sticky" value="1"'
                . ($sticky ? ' checked' : '') . ' /> Stick this post to the front page</label>';
            $html .= '</div></div>';
        }

        $html .= '</div>'; // sidebar
        $html .= '</div>'; // layout
        $html .= '</form>';

        // Unused var silence for future screen title.
        unset($typeLabel);

        return $html;
    }

    /**
     * Sidebar revisions summary + link to full history.
     */
    public static function renderRevisionsMetabox(
        int $postId,
        string $postType,
        int $userId,
        AP_DB $db
    ): string {
        $count = AP_Post::countRevisions($postId, false, $db);
        $autosave = AP_Post::getAutosave($postId, $userId > 0 ? $userId : 0, $db);
        $historyUrl = AP_Admin::url('revision.php', [
            'post' => $postId,
            'post_type' => $postType,
        ]);

        $html = '<div class="ap-metabox ap-metabox-revisions">';
        $html .= '<h3 class="ap-metabox-title">Revisions</h3>';
        $html .= '<div class="ap-metabox-body">';
        if ($count < 1 && $autosave === null) {
            $html .= '<p class="description">No revisions yet. Revisions are saved '
                . 'when you update title, content, or excerpt.</p>';
        } else {
            $html .= '<p>';
            $html .= $count === 1
                ? '1 revision'
                : (string) $count . ' revisions';
            if ($autosave !== null) {
                $html .= ' · autosave available';
            }
            $html .= '</p>';
            $html .= '<p><a href="' . ap_esc_url($historyUrl) . '">Browse history</a></p>';
        }
        $html .= '</div></div>';

        return $html;
    }

    /**
     * Full revision history list HTML for revision.php.
     */
    public static function renderRevisionsList(
        AP_Post $parent,
        int $userId = 0,
        ?AP_DB $db = null
    ): string {
        $db = $db ?? ap_db();
        $revisions = AP_Post::getRevisions($parent->ID, [
            'include_autosaves' => true,
            'limit' => 0,
        ], $db);

        $html = '<div class="ap-revisions-list">';
        if ($revisions === []) {
            $html .= '<p>No revisions have been saved for this item yet.</p>';
            $html .= '</div>';

            return $html;
        }

        $html .= '<table class="ap-list-table widefat striped">';
        $html .= '<thead><tr>';
        $html .= '<th scope="col">Date</th>';
        $html .= '<th scope="col">Title</th>';
        $html .= '<th scope="col">Type</th>';
        $html .= '<th scope="col">Actions</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($revisions as $rev) {
            $isAutosave = AP_Post::isAutosave($rev);
            $date = $rev->post_modified !== '' ? $rev->post_modified : $rev->post_date;
            $title = $rev->post_title !== '' ? $rev->post_title : '(no title)';
            $typeLabel = $isAutosave ? 'Autosave' : 'Revision';

            $restoreUrl = AP_Admin::url('revision.php', [
                'post' => $parent->ID,
                'action' => 'restore',
                'revision' => $rev->ID,
                '_ap_nonce' => ap_create_nonce(
                    'restore-revision-' . $rev->ID,
                    $userId > 0 ? $userId : null
                ),
            ]);
            $deleteUrl = AP_Admin::url('revision.php', [
                'post' => $parent->ID,
                'action' => 'delete',
                'revision' => $rev->ID,
                '_ap_nonce' => ap_create_nonce(
                    'delete-revision-' . $rev->ID,
                    $userId > 0 ? $userId : null
                ),
            ]);

            $html .= '<tr>';
            $html .= '<td>' . ap_esc_html($date) . '</td>';
            $html .= '<td>' . ap_esc_html($title) . '</td>';
            $html .= '<td>' . ap_esc_html($typeLabel) . '</td>';
            $html .= '<td class="ap-row-actions">';
            $html .= '<a href="' . ap_esc_url($restoreUrl) . '">Restore</a>';
            $html .= ' | <a class="submitdelete" href="' . ap_esc_url($deleteUrl) . '">Delete</a>';
            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        return $html;
    }

    private static function renderStatusSelect(string $current): string
    {
        $options = [
            'draft' => 'Draft',
            'pending' => 'Pending Review',
            'publish' => 'Published',
            'private' => 'Private',
            'future' => 'Scheduled',
        ];
        $html = '<select name="post_status" id="post_status">';
        foreach ($options as $value => $label) {
            $sel = $current === $value ? ' selected' : '';
            $html .= '<option value="' . ap_esc_attr($value) . '"' . $sel . '>'
                . ap_esc_html($label) . '</option>';
        }
        // If current is trash / auto-draft, still show it disabled-ish.
        if (!isset($options[$current]) && $current !== '') {
            $html .= '<option value="' . ap_esc_attr($current) . '" selected>'
                . ap_esc_html($current) . '</option>';
        }
        $html .= '</select>';

        return $html;
    }

    private static function renderParentSelect(
        string $postType,
        int $selected,
        int $excludeId,
        ?AP_DB $db
    ): string {
        $html = '<select name="post_parent" id="post_parent">';
        $html .= '<option value="0">(no parent)</option>';

        if ($db instanceof AP_DB) {
            $pages = AP_Post::query([
                'post_type' => $postType,
                'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
                'orderby' => 'menu_order',
                'order' => 'ASC',
                'limit' => 0,
                'exclude' => $excludeId > 0 ? [$excludeId] : [],
            ], $db);

            // Exclude self and descendants to prevent cycles in the UI.
            $exclude = [];
            if ($excludeId > 0) {
                $exclude[$excludeId] = true;
                // Cheap: skip posts that would cycle (ancestors of exclude are OK; children not).
                foreach ($pages as $p) {
                    if (AP_Post::wouldCreateCycle($excludeId, $p->ID, $db)) {
                        // wouldCreateCycle(id, newParent): true if id is ancestor of newParent
                        // — meaning selecting $p as parent of $excludeId creates a cycle
                        // when $excludeId is ancestor of $p. So exclude descendants of self.
                        $exclude[$p->ID] = true;
                    }
                }
            }

            foreach ($pages as $p) {
                if (isset($exclude[$p->ID])) {
                    continue;
                }
                $depth = count(AP_Post::getAncestorIds($p->ID, $db));
                $pad = str_repeat('— ', $depth);
                $label = $p->post_title !== '' ? $p->post_title : '(no title)';
                $sel = $selected === $p->ID ? ' selected' : '';
                $html .= '<option value="' . (int) $p->ID . '"' . $sel . '>'
                    . ap_esc_html($pad . $label) . '</option>';
            }
        }

        $html .= '</select>';

        return $html;
    }

    private static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        $status = preg_replace('/[^a-z0-9_\-]/', '', $status) ?? '';
        if ($status === '' || !AP_Post::statusExists($status)) {
            return 'draft';
        }
        if (in_array($status, ['trash', 'auto-draft', 'inherit'], true)) {
            return 'draft';
        }

        return $status;
    }
}
