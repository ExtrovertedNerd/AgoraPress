<?php

/**
 * Admin media upload + attachment edit logic.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Media form save / upload / render helpers for ap-admin.
 */
class AP_Admin_Media
{
    /**
     * Process one or more uploaded files from $_FILES['async-upload'] or ['media_file'].
     *
     * @param array<string, mixed> $files  $_FILES entry or multi-file structure.
     * @param array<string, mixed> $input  POST bag (nonce, parent, …).
     *
     * @return array{
     *   ok: bool,
     *   message_key: string,
     *   count: int,
     *   ids: list<int>,
     *   errors: list<string>
     * }
     */
    public static function processUpload(array $files, array $input, int $userId, ?AP_DB $db = null): array
    {
        $nonce = (string) ($input['_ap_nonce'] ?? '');
        if (!ap_check_nonce($nonce, 'media-upload', $userId > 0 ? $userId : null)) {
            return [
                'ok' => false,
                'message_key' => 'nonce',
                'count' => 0,
                'ids' => [],
                'errors' => ['Security check failed. Please reload and try again.'],
            ];
        }

        $normalized = self::normalizeFilesArray($files);
        if ($normalized === []) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'count' => 0,
                'ids' => [],
                'errors' => ['No files were selected.'],
            ];
        }

        $parent = max(0, (int) ($input['post_parent'] ?? 0));
        $ids = [];
        $errors = [];

        foreach ($normalized as $file) {
            $result = AP_Media::handleUpload($file, [
                'post_author' => $userId,
                'post_parent' => $parent,
            ], $db);

            if ($result['ok'] && $result['id'] > 0) {
                $ids[] = $result['id'];
            } else {
                $name = (string) ($file['name'] ?? 'file');
                $errors[] = $name . ': ' . ($result['error'] !== '' ? $result['error'] : 'Upload failed.');
            }
        }

        $count = count($ids);
        if ($count < 1) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'count' => 0,
                'ids' => [],
                'errors' => $errors !== [] ? $errors : ['Upload failed.'],
            ];
        }

        return [
            'ok' => true,
            'message_key' => $count === 1 ? 'uploaded' : 'bulk_uploaded',
            'count' => $count,
            'ids' => $ids,
            'errors' => $errors,
        ];
    }

    /**
     * Save attachment details form.
     *
     * @param array<string, mixed> $input
     *
     * @return array{
     *   ok: bool,
     *   id: int,
     *   message_key: string,
     *   errors: list<string>,
     *   post: ?AP_Post
     * }
     */
    public static function save(array $input, int $userId, ?AP_DB $db = null): array
    {
        $db = $db ?? ap_db();
        $id = (int) ($input['attachment_id'] ?? $input['post_ID'] ?? 0);
        if ($id < 1) {
            return [
                'ok' => false,
                'id' => 0,
                'message_key' => 'not_found',
                'errors' => ['Missing attachment.'],
                'post' => null,
            ];
        }

        $nonce = (string) ($input['_ap_nonce'] ?? '');
        if (!ap_check_nonce($nonce, 'update-media-' . $id, $userId > 0 ? $userId : null)) {
            return [
                'ok' => false,
                'id' => $id,
                'message_key' => 'nonce',
                'errors' => ['Security check failed. Please reload and try again.'],
                'post' => AP_Post::get($id, $db),
            ];
        }

        $post = AP_Post::get($id, $db);
        if ($post === null || $post->post_type !== 'attachment') {
            return [
                'ok' => false,
                'id' => $id,
                'message_key' => 'not_found',
                'errors' => ['That attachment could not be found.'],
                'post' => null,
            ];
        }

        $title = ap_sanitize_text_field((string) ($input['post_title'] ?? ''));
        $caption = ap_sanitize_textarea_field((string) ($input['post_excerpt'] ?? ''));
        $description = ap_sanitize_textarea_field((string) ($input['post_content'] ?? ''));
        $alt = ap_sanitize_text_field((string) ($input['alt_text'] ?? ''));
        $parent = max(0, (int) ($input['post_parent'] ?? $post->post_parent));

        $ok = AP_Media::updateAttachment($id, [
            'post_title' => $title,
            'post_excerpt' => $caption,
            'post_content' => $description,
            'post_parent' => $parent,
            'alt_text' => $alt,
        ], $db);

        return [
            'ok' => $ok,
            'id' => $id,
            'message_key' => $ok ? 'updated' : 'error',
            'errors' => $ok ? [] : ['Could not save attachment details.'],
            'post' => AP_Post::get($id, $db),
        ];
    }

    /**
     * HTML for the multi-file upload panel (library screen).
     */
    public static function renderUploadForm(int $userId = 0): string
    {
        $action = ap_esc_url(AP_Admin::url('upload.php'));
        $max = AP_Media::formatBytes(AP_Media::maxUploadBytes());
        $html = '<div class="ap-media-upload-panel">';
        $html .= '<form class="ap-media-upload-form" method="post" action="' . $action
            . '" enctype="multipart/form-data">';
        $html .= ap_nonce_field('media-upload', '_ap_nonce', false, $userId > 0 ? $userId : null);
        $html .= '<input type="hidden" name="ap_media_action" value="upload" />';
        $html .= '<div class="ap-media-dropzone" id="ap-media-dropzone">';
        $html .= '<p class="ap-media-dropzone-label"><strong>Upload files</strong></p>';
        $html .= '<p class="description">Select one or more files (max ' . ap_esc_html($max)
            . ' each), or drag and drop them onto this panel.</p>';
        $html .= '<label class="screen-reader-text" for="ap-media-file-input">Choose files</label>';
        $html .= '<input type="file" name="media_file[]" id="ap-media-file-input" multiple '
            . 'accept="' . ap_esc_attr(self::acceptAttribute()) . '" />';
        $html .= '<p class="ap-media-upload-actions">'
            . '<button type="submit" class="button button-primary">Upload</button>'
            . '</p>';
        $html .= '</div></form></div>';

        return $html;
    }

    /**
     * Attachment details edit form HTML.
     */
    public static function renderEditForm(AP_Post $post, int $userId = 0): string
    {
        $id = (int) $post->ID;
        $url = AP_Media::getAttachmentUrl($id);
        $relative = AP_Media::getAttachedFileRelative($id);
        $meta = AP_Media::getMetadata($id);
        $alt = AP_Media::getAltText($id);
        $isImage = AP_Media::isImageMime($post->post_mime_type);

        $action = ap_esc_url(AP_Admin::url('media.php', ['item' => $id]));
        $html = '<form method="post" action="' . $action . '" class="ap-media-edit-form">';
        $html .= ap_nonce_field('update-media-' . $id, '_ap_nonce', false, $userId > 0 ? $userId : null);
        $html .= '<input type="hidden" name="attachment_id" value="' . $id . '" />';
        $html .= '<input type="hidden" name="ap_media_action" value="save" />';

        $html .= '<div class="ap-media-edit-layout">';

        $html .= '<div class="ap-media-edit-preview">';
        if ($isImage && $url !== '') {
            $imgAlt = ap_esc_attr($alt !== '' ? $alt : $post->post_title);
            $html .= '<img src="' . ap_esc_url($url) . '" alt="' . $imgAlt
                . '" class="ap-media-preview-img" />';
        } else {
            $extLabel = strtoupper(pathinfo($relative, PATHINFO_EXTENSION) ?: 'FILE');
            $html .= '<div class="ap-media-preview-file">'
                . '<span class="ap-media-icon">' . ap_esc_html($extLabel)
                . '</span></div>';
        }
        $html .= '<p class="ap-media-meta-line"><strong>File URL</strong><br />';
        if ($url !== '') {
            $html .= '<a href="' . ap_esc_url($url) . '" target="_blank" rel="noopener">'
                . ap_esc_html($url) . '</a>';
            $html .= '<br /><input type="text" class="ap-media-url-field widefat" readonly value="'
                . ap_esc_attr($url) . '" onclick="this.select();" aria-label="File URL" />';
        } else {
            $html .= '<em>Unavailable</em>';
        }
        $html .= '</p>';
        $html .= '<p class="ap-media-meta-line"><strong>File name</strong><br />'
            . ap_esc_html($relative !== '' ? basename($relative) : '—') . '</p>';
        $html .= '<p class="ap-media-meta-line"><strong>File type</strong><br />'
            . ap_esc_html($post->post_mime_type) . '</p>';
        if (!empty($meta['filesize'])) {
            $html .= '<p class="ap-media-meta-line"><strong>File size</strong><br />'
                . ap_esc_html(AP_Media::formatBytes((int) $meta['filesize'])) . '</p>';
        }
        if (!empty($meta['width']) && !empty($meta['height'])) {
            $html .= '<p class="ap-media-meta-line"><strong>Dimensions</strong><br />'
                . (int) $meta['width'] . ' × ' . (int) $meta['height'] . '</p>';
        }
        $html .= '<p class="ap-media-meta-line"><strong>Uploaded</strong><br />'
            . ap_esc_html($post->post_date) . '</p>';
        $html .= '</div>';

        $html .= '<div class="ap-media-edit-fields">';
        $html .= '<p><label for="post_title"><strong>Title</strong></label><br />';
        $html .= '<input type="text" class="widefat" name="post_title" id="post_title" value="'
            . ap_esc_attr($post->post_title) . '" /></p>';

        if ($isImage) {
            $html .= '<p><label for="alt_text"><strong>Alt text</strong></label><br />';
            $html .= '<input type="text" class="widefat" name="alt_text" id="alt_text" value="'
                . ap_esc_attr($alt) . '" />';
            $html .= '<span class="description">Describe the image for screen readers and SEO.</span></p>';
        } else {
            $html .= '<input type="hidden" name="alt_text" value="' . ap_esc_attr($alt) . '" />';
        }

        $html .= '<p><label for="post_excerpt"><strong>Caption</strong></label><br />';
        $html .= '<textarea class="widefat" name="post_excerpt" id="post_excerpt" rows="3">'
            . ap_esc_textarea($post->post_excerpt) . '</textarea></p>';

        $html .= '<p><label for="post_content"><strong>Description</strong></label><br />';
        $html .= '<textarea class="widefat" name="post_content" id="post_content" rows="5">'
            . ap_esc_textarea($post->post_content) . '</textarea></p>';

        $html .= '<p><label for="post_parent"><strong>Attached to (post ID)</strong></label><br />';
        $html .= '<input type="number" class="small-text" name="post_parent" id="post_parent" min="0" value="'
            . (int) $post->post_parent . '" />';
        $html .= '<span class="description">0 = unattached.</span></p>';

        $html .= '<p class="ap-media-edit-actions">';
        $html .= '<button type="submit" class="button button-primary" name="save_action" value="save">Update</button> ';
        $deleteNonce = ap_create_nonce('delete-media-' . $id, $userId > 0 ? $userId : null);
        $deleteUrl = AP_Admin::url('upload.php', [
            'action' => 'delete',
            'media' => $id,
            '_ap_nonce' => $deleteNonce,
        ]);
        $html .= '<a class="button ap-button-danger" href="' . ap_esc_url($deleteUrl)
            . '" onclick="return confirm(\'Delete this file permanently?\');">Delete permanently</a>';
        $html .= '</p>';
        $html .= '</div></div></form>';

        return $html;
    }

    /**
     * Normalize single or multi-file $_FILES structure into a list of file arrays.
     *
     * @param array<string, mixed> $files
     *
     * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    public static function normalizeFilesArray(array $files): array
    {
        // Multi: name is array.
        if (isset($files['name']) && is_array($files['name'])) {
            $out = [];
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                $err = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $out[] = [
                    'name' => (string) ($files['name'][$i] ?? ''),
                    'type' => (string) ($files['type'][$i] ?? ''),
                    'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                    'error' => $err,
                    'size' => (int) ($files['size'][$i] ?? 0),
                ];
            }

            return $out;
        }

        // Single file.
        if (isset($files['tmp_name']) && is_string($files['tmp_name'])) {
            $err = (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) {
                return [];
            }

            return [[
                'name' => (string) ($files['name'] ?? ''),
                'type' => (string) ($files['type'] ?? ''),
                'tmp_name' => (string) $files['tmp_name'],
                'error' => $err,
                'size' => (int) ($files['size'] ?? 0),
            ]];
        }

        return [];
    }

    /**
     * accept= attribute built from the allow-list.
     */
    public static function acceptAttribute(): string
    {
        $parts = [];
        foreach (array_keys(AP_Media::allowedMimes()) as $ext) {
            $parts[] = '.' . $ext;
        }

        return implode(',', $parts);
    }
}
