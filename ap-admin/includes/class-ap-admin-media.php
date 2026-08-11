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
     * Capability required to set or clear the site icon (Settings → General).
     */
    public const SITE_ICON_CAPABILITY = 'manage_options';

    /**
     * Nonce action shared with the General settings form (AP_Settings group "general").
     */
    public const SITE_ICON_NONCE_ACTION = 'ap_settings_general';

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

        if (!AP_Admin::userCan($userId, 'upload_files', null, $db)) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'count' => 0,
                'ids' => [],
                'errors' => ['You do not have permission to upload files.'],
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
     * Save attachment details form (metadata and/or image scale/crop).
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

        // Meta-cap maps own vs others; also require upload_files for media library access.
        if (
            !AP_Admin::userCan($userId, 'upload_files', null, $db)
            || !AP_Admin::userCan($userId, 'edit_post', $id, $db)
        ) {
            return [
                'ok' => false,
                'id' => $id,
                'message_key' => 'error',
                'errors' => ['You do not have permission to edit this media item.'],
                'post' => $post,
            ];
        }

        $saveAction = (string) ($input['save_action'] ?? 'save');

        // Image scale / crop (destructive on the original file).
        if ($saveAction === 'edit_image') {
            $maxW = max(0, (int) ($input['image_scale_w'] ?? 0));
            $maxH = max(0, (int) ($input['image_scale_h'] ?? 0));
            $crop = !empty($input['image_crop']);
            $edit = AP_Media::editImage($id, $maxW, $maxH, $crop, $db);
            if (!$edit['ok']) {
                return [
                    'ok' => false,
                    'id' => $id,
                    'message_key' => 'error',
                    'errors' => [$edit['error'] !== '' ? $edit['error'] : 'Could not edit the image.'],
                    'post' => AP_Post::get($id, $db),
                ];
            }

            return [
                'ok' => true,
                'id' => $id,
                'message_key' => 'image_edited',
                'errors' => [],
                'post' => AP_Post::get($id, $db),
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
    public static function renderEditForm(AP_Post $post, int $userId = 0, ?AP_DB $db = null): string
    {
        $id = (int) $post->ID;
        $url = AP_Media::getAttachmentUrl($id, $db);
        $relative = AP_Media::getAttachedFileRelative($id, $db);
        $meta = AP_Media::getMetadata($id, $db);
        $alt = AP_Media::getAltText($id, $db);
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
        $html .= '</div>'; // .ap-media-edit-fields

        // Image scale / crop (attachment details).
        if ($isImage && !str_contains(strtolower($post->post_mime_type), 'svg')) {
            $curW = (int) ($meta['width'] ?? 0);
            $curH = (int) ($meta['height'] ?? 0);
            $gdOk = AP_Media::gdAvailable();
            $html .= '<div class="ap-media-edit-image ap-media-edit-fields">';
            $html .= '<h2 class="ap-media-edit-image-title">Scale / crop</h2>';
            if (!$gdOk) {
                $html .= '<p class="description">Image editing requires the PHP <code>gd</code> extension.</p>';
            } else {
                $html .= '<p class="description">'
                    . 'Resize the original file on disk. Leave one dimension empty to preserve aspect ratio. '
                    . 'Optional crop uses the width and height as exact target dimensions (center crop). '
                    . 'This cannot be undone — download a copy first if you need the original.'
                    . '</p>';
                $html .= '<p class="ap-field ap-media-scale-fields">';
                $html .= '<label for="image_scale_w">Max width</label> ';
                $html .= '<input type="number" class="small-text" name="image_scale_w" id="image_scale_w" '
                    . 'min="0" max="10000" value="' . ($curW > 0 ? $curW : '') . '" placeholder="px"> ';
                $html .= '<label for="image_scale_h">Max height</label> ';
                $html .= '<input type="number" class="small-text" name="image_scale_h" id="image_scale_h" '
                    . 'min="0" max="10000" value="' . ($curH > 0 ? $curH : '') . '" placeholder="px">';
                $html .= '</p>';
                $html .= '<p class="ap-field">';
                $html .= '<label class="ap-inline-option">'
                    . '<input type="checkbox" name="image_crop" id="image_crop" value="1"> '
                    . 'Crop to exact dimensions (center)</label>';
                $html .= '</p>';
                if ($curW > 0 && $curH > 0) {
                    $html .= '<p class="description">Current size: '
                        . $curW . ' × ' . $curH . ' px</p>';
                }
                $maxDisplay = AP_Media::maxDisplayWidth($db);
                if ($maxDisplay > 0) {
                    $html .= '<p class="description">Site max display width for content images: '
                        . $maxDisplay . ' px (Settings → Media).</p>';
                }
                $html .= '<p class="ap-media-edit-actions">';
                $html .= '<button type="submit" class="button" name="save_action" value="edit_image"'
                    . ' onclick="return confirm(\'Scale or crop the original image file? This cannot be undone.\');">'
                    . 'Apply scale / crop</button>';
                $html .= '</p>';
            }
            $html .= '</div>';
        }

        $html .= '</div></form>'; // .ap-media-edit-layout + form

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

    /**
     * accept= for site icon uploads (raster + ico; no SVG for derivative pipeline).
     */
    public static function siteIconAcceptAttribute(): string
    {
        return 'image/jpeg,image/png,image/gif,image/webp,image/x-icon,image/vnd.microsoft.icon'
            . ',.jpg,.jpeg,.png,.gif,.webp,.ico';
    }

    /**
     * Whether an attachment is suitable as a site icon (exists + non-SVG image).
     */
    public static function isUsableSiteIcon(int $id, ?AP_DB $db = null): bool
    {
        if ($id < 1 || !class_exists('AP_Media', false) || !class_exists('AP_Post', false)) {
            return false;
        }

        $post = AP_Post::get($id, $db);
        if ($post === null || $post->post_type !== 'attachment') {
            return false;
        }

        $mime = strtolower(trim((string) $post->post_mime_type));
        if ($mime === '' || str_contains($mime, 'svg')) {
            return false;
        }

        return AP_Media::isImageMime($mime);
    }

    /**
     * Resolve site_icon attachment ID from General settings form input + optional upload.
     *
     * Priority: new upload → remove checkbox → posted site_icon (library / hidden).
     * Non-image or missing attachments coerce to 0 with an error when explicitly set.
     *
     * @param array<string, mixed> $input      POST bag
     * @param array<string, mixed> $files      $_FILES bag (site_icon_upload key)
     * @param array<string, mixed> $uploadArgs Extra AP_Media::handleUpload args (e.g. test_mode)
     *
     * @return array{ok: bool, site_icon: int, errors: list<string>}
     */
    public static function resolveSiteIconInput(
        array $input,
        array $files,
        int $userId,
        ?AP_DB $db = null,
        array $uploadArgs = []
    ): array {
        $db = $db ?? (function_exists('ap_db') ? ap_db() : null);
        $remove = !empty($input['remove_site_icon']);

        $file = $files['site_icon_upload'] ?? null;
        $hasUpload = is_array($file)
            && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
            && (string) ($file['tmp_name'] ?? '') !== '';

        if ($hasUpload) {
            if ($userId > 0 && class_exists('AP_Admin', false)
                && !AP_Admin::userCan($userId, 'upload_files', null, $db)
            ) {
                return [
                    'ok' => false,
                    'site_icon' => max(0, (int) ($input['site_icon'] ?? 0)),
                    'errors' => ['You do not have permission to upload files.'],
                ];
            }

            /** @var array<string, mixed> $file */
            $args = array_merge([
                'post_author' => $userId,
                'post_title' => 'Site Icon',
                'alt_text' => 'Site Icon',
                'skip_rate_limit' => !empty($uploadArgs['skip_rate_limit']),
            ], $uploadArgs);

            $result = AP_Media::handleUpload($file, $args, $db);
            if (!$result['ok'] || $result['id'] < 1) {
                return [
                    'ok' => false,
                    'site_icon' => max(0, (int) ($input['site_icon'] ?? 0)),
                    'errors' => [
                        $result['error'] !== ''
                            ? $result['error']
                            : 'Could not upload the site icon.',
                    ],
                ];
            }

            if (!self::isUsableSiteIcon($result['id'], $db)) {
                // Uploaded something non-image somehow — do not keep it as icon.
                return [
                    'ok' => false,
                    'site_icon' => max(0, (int) ($input['site_icon'] ?? 0)),
                    'errors' => ['Site icon must be a JPEG, PNG, GIF, WebP, or ICO image.'],
                ];
            }

            return [
                'ok' => true,
                'site_icon' => $result['id'],
                'errors' => [],
            ];
        }

        if ($remove) {
            return [
                'ok' => true,
                'site_icon' => 0,
                'errors' => [],
            ];
        }

        // Library select or hidden field.
        if (!array_key_exists('site_icon', $input)) {
            // Partial save — leave caller to omit the key so option is preserved.
            return [
                'ok' => true,
                'site_icon' => -1,
                'errors' => [],
            ];
        }

        $id = max(0, (int) $input['site_icon']);
        if ($id === 0) {
            return [
                'ok' => true,
                'site_icon' => 0,
                'errors' => [],
            ];
        }

        if (!self::isUsableSiteIcon($id, $db)) {
            return [
                'ok' => false,
                'site_icon' => 0,
                'errors' => ['Selected site icon is not a valid image attachment.'],
            ];
        }

        return [
            'ok' => true,
            'site_icon' => $id,
            'errors' => [],
        ];
    }

    /**
     * Whether the user may set or clear the site icon option.
     */
    public static function userCanManageSiteIcon(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }
        if (class_exists('AP_Admin', false)) {
            return AP_Admin::userCan($userId, self::SITE_ICON_CAPABILITY, null, $db);
        }
        if (class_exists('AP_Roles', false)) {
            return AP_Roles::userCan($userId, self::SITE_ICON_CAPABILITY, null, $db);
        }

        return false;
    }

    /**
     * Secure site-icon save for Settings → General.
     *
     * Enforces:
     * 1. Valid `_ap_nonce` for {@see self::SITE_ICON_NONCE_ACTION} (same as general settings)
     * 2. {@see self::SITE_ICON_CAPABILITY} (`manage_options`)
     * 3. {@see resolveSiteIconInput} (upload / library / remove)
     *
     * By default only resolves the attachment ID for merging into
     * {@see AP_Options::updateGeneralSettings}. Pass `$args['persist'] => true`
     * to write the `site_icon` option immediately (unit tests / isolated saves).
     *
     * @param array<string, mixed> $input POST bag (must include `_ap_nonce`)
     * @param array<string, mixed> $files $_FILES bag
     * @param array{
     *   persist?: bool,
     *   skip_rate_limit?: bool,
     *   test_mode?: bool
     * } $args Extra flags (upload test_mode, skip_rate_limit, persist)
     *
     * @return array{
     *   ok: bool,
     *   message_key: string,
     *   site_icon: int,
     *   errors: list<string>,
     *   saved: bool
     * }
     */
    public static function processSiteIconSave(
        array $input,
        array $files,
        int $userId,
        ?AP_DB $db = null,
        array $args = []
    ): array {
        $db = $db ?? (function_exists('ap_db') ? ap_db() : null);
        $fail = static function (string $key, string $error, int $icon = -1): array {
            return [
                'ok' => false,
                'message_key' => $key,
                'site_icon' => $icon,
                'errors' => [$error],
                'saved' => false,
            ];
        };

        $nonce = (string) ($input['_ap_nonce'] ?? '');
        if (
            $nonce === ''
            || !function_exists('ap_check_nonce')
            || !ap_check_nonce($nonce, self::SITE_ICON_NONCE_ACTION, $userId > 0 ? $userId : null)
        ) {
            return $fail('nonce', 'Security check failed. Please try again.');
        }

        if (!self::userCanManageSiteIcon($userId, $db)) {
            return $fail(
                'cap',
                'You do not have permission to manage site settings.'
            );
        }

        $uploadArgs = [];
        if (!empty($args['test_mode'])) {
            $uploadArgs['test_mode'] = true;
        }
        if (!empty($args['skip_rate_limit'])) {
            $uploadArgs['skip_rate_limit'] = true;
        }

        $resolved = self::resolveSiteIconInput($input, $files, $userId, $db, $uploadArgs);
        if (!$resolved['ok']) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'site_icon' => $resolved['site_icon'],
                'errors' => $resolved['errors'],
                'saved' => false,
            ];
        }

        $saved = false;
        $persist = !empty($args['persist']);
        if ($persist && $resolved['site_icon'] >= 0 && class_exists('AP_Options', false)) {
            $previousIcon = AP_Options::siteIcon($db);
            $newIcon = max(0, (int) $resolved['site_icon']);
            $ok = AP_Options::update(
                'site_icon',
                (string) $newIcon,
                $db
            );
            if (!$ok) {
                return $fail('error', 'Could not save the site icon.', $newIcon);
            }
            $saved = true;
            // Cleanup previous pack on remove/replace; generate for the new icon when set.
            AP_Options::applySiteIconChange($previousIcon, $newIcon, $db);
        }

        return [
            'ok' => true,
            'message_key' => 'ok',
            'site_icon' => $resolved['site_icon'],
            'errors' => [],
            'saved' => $saved,
        ];
    }

    /**
     * HTML for Settings → General → Site Icon (preview, library pick, upload, remove).
     */
    public static function renderSiteIconField(int $currentId, int $userId = 0, ?AP_DB $db = null): string
    {
        $currentId = max(0, $currentId);
        $previewUrl = '';
        $previewTitle = '';
        $hasIcon = false;

        if ($currentId > 0 && self::isUsableSiteIcon($currentId, $db)) {
            $hasIcon = true;
            $previewUrl = AP_Media::getAttachmentUrl($currentId, $db);
            $post = AP_Post::get($currentId, $db);
            $previewTitle = $post !== null && $post->post_title !== ''
                ? $post->post_title
                : 'Site Icon';
        } elseif ($currentId > 0) {
            // Stale / non-image ID still shown as ID but no preview.
            $currentId = 0;
        }

        $library = self::listSiteIconCandidates(40, $db);

        $html = '<div class="ap-site-icon-picker" data-ap-site-icon-picker>';
        $html .= '<label class="ap-site-icon-label" for="site_icon">Site Icon</label>';

        $html .= '<div class="ap-site-icon-preview" data-ap-site-icon-preview>';
        if ($hasIcon && $previewUrl !== '') {
            $html .= '<img src="' . ap_esc_url($previewUrl) . '" alt="'
                . ap_esc_attr($previewTitle) . '" class="ap-site-icon-img" width="96" height="96"'
                . ' data-ap-site-icon-img />';
            $html .= '<p class="description ap-site-icon-status" data-ap-site-icon-status>'
                . 'Current icon (attachment #' . (int) $currentId . ').</p>';
        } else {
            $html .= '<span class="ap-site-icon-placeholder" data-ap-site-icon-placeholder'
                . ' aria-hidden="true">No icon</span>';
            $html .= '<p class="description ap-site-icon-status" data-ap-site-icon-status>'
                . 'No site icon set. Browsers may use a root <code>favicon.ico</code> if present.</p>';
        }
        $html .= '</div>';

        // Hidden stores the chosen attachment ID for save (library / remove / upload path).
        $html .= '<input type="hidden" name="site_icon" id="site_icon" value="'
            . (int) $currentId . '" data-ap-site-icon-id />';

        $html .= '<div class="ap-field ap-site-icon-library">';
        $html .= '<label for="site_icon_library">Choose from Media Library</label>';
        $html .= '<select id="site_icon_library" class="regular-text" data-ap-site-icon-library>';
        $html .= '<option value="0"' . ($currentId === 0 ? ' selected' : '') . '>— None —</option>';
        $foundCurrent = $currentId === 0;
        foreach ($library as $item) {
            $id = (int) $item['id'];
            $sel = $id === $currentId ? ' selected' : '';
            if ($id === $currentId) {
                $foundCurrent = true;
            }
            $label = $item['title'] !== '' ? $item['title'] : ('Attachment #' . $id);
            $html .= '<option value="' . $id . '"' . $sel
                . ' data-url="' . ap_esc_attr($item['url']) . '">'
                . ap_esc_html($label) . ' (#' . $id . ')</option>';
        }
        // Current icon not in recent list — still keep it selectable.
        if (!$foundCurrent && $hasIcon) {
            $html .= '<option value="' . (int) $currentId . '" selected data-url="'
                . ap_esc_attr($previewUrl) . '">'
                . ap_esc_html($previewTitle !== '' ? $previewTitle : ('Attachment #' . $currentId))
                . ' (#' . (int) $currentId . ')</option>';
        }
        $html .= '</select>';
        $html .= '<span class="ap-help">Recent image attachments. Or upload a new file below.</span>';
        $html .= '</div>';

        $html .= '<div class="ap-field ap-site-icon-upload">';
        $html .= '<label for="site_icon_upload">Upload new icon</label>';
        $html .= '<input type="file" name="site_icon_upload" id="site_icon_upload"'
            . ' accept="' . ap_esc_attr(self::siteIconAcceptAttribute()) . '"'
            . ' data-ap-site-icon-upload />';
        $html .= '<span class="ap-help">JPG, PNG, GIF, WebP, or ICO. Square images work best'
            . ' (at least 512×512 recommended). Replaces the current selection on save.</span>';
        $html .= '</div>';

        $html .= '<div class="ap-field ap-site-icon-remove"'
            . ($hasIcon ? '' : ' hidden') . ' data-ap-site-icon-remove-wrap>';
        $html .= '<label class="ap-checkbox-label">';
        $html .= '<input type="checkbox" name="remove_site_icon" id="remove_site_icon" value="1"'
            . ' data-ap-site-icon-remove /> ';
        $html .= 'Remove site icon</label>';
        $html .= '<span class="ap-help">Clears the favicon set after save. Does not delete the media file.</span>';
        $html .= '</div>';

        if (class_exists('AP_Admin', false)) {
            $libUrl = AP_Admin::url('upload.php', ['mime_type' => 'image']);
            $html .= '<p class="description">'
                . '<a href="' . ap_esc_url($libUrl) . '">Open Media Library</a>'
                . ' to manage uploads, then return here to select an image.</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Recent image attachments suitable for the site icon library select.
     *
     * @return list<array{id: int, title: string, url: string}>
     */
    public static function listSiteIconCandidates(int $limit = 40, ?AP_DB $db = null): array
    {
        if (!class_exists('AP_Media', false)) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $result = AP_Media::query([
            'mime_type' => 'image/*',
            'limit' => $limit,
            'orderby' => 'post_date',
            'order' => 'DESC',
        ], $db);

        $out = [];
        foreach ($result['items'] as $post) {
            if (!$post instanceof AP_Post) {
                continue;
            }
            $id = (int) $post->ID;
            if (!self::isUsableSiteIcon($id, $db)) {
                continue;
            }
            $url = AP_Media::getAttachmentUrl($id, $db);
            if ($url === '') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'title' => (string) $post->post_title,
                'url' => $url,
            ];
        }

        return $out;
    }

    /**
     * Inline progressive-enhancement script for the site icon picker preview.
     */
    public static function siteIconPickerScript(): string
    {
        return <<<'JS'
(function () {
    var root = document.querySelector('[data-ap-site-icon-picker]');
    if (!root) { return; }
    var hidden = root.querySelector('[data-ap-site-icon-id]');
    var library = root.querySelector('[data-ap-site-icon-library]');
    var upload = root.querySelector('[data-ap-site-icon-upload]');
    var remove = root.querySelector('[data-ap-site-icon-remove]');
    var removeWrap = root.querySelector('[data-ap-site-icon-remove-wrap]');
    var preview = root.querySelector('[data-ap-site-icon-preview]');
    var status = root.querySelector('[data-ap-site-icon-status]');
    var objectUrl = null;

    function revoke() {
        if (objectUrl) {
            try { URL.revokeObjectURL(objectUrl); } catch (e) {}
            objectUrl = null;
        }
    }

    function setPreview(url, label, id) {
        if (!preview) { return; }
        var img = preview.querySelector('[data-ap-site-icon-img]');
        var ph = preview.querySelector('[data-ap-site-icon-placeholder]');
        if (url) {
            if (!img) {
                if (ph) { ph.remove(); }
                img = document.createElement('img');
                img.className = 'ap-site-icon-img';
                img.width = 96;
                img.height = 96;
                img.setAttribute('data-ap-site-icon-img', '');
                preview.insertBefore(img, status || null);
            }
            img.src = url;
            img.alt = label || 'Site Icon';
            if (status) {
                status.innerHTML = id > 0
                    ? ('Selected icon (attachment #' + id + '). Save to apply.')
                    : 'Preview of selected file. Save to apply.';
            }
            if (removeWrap) { removeWrap.hidden = false; }
        } else {
            revoke();
            if (img) { img.remove(); }
            if (!preview.querySelector('[data-ap-site-icon-placeholder]')) {
                var span = document.createElement('span');
                span.className = 'ap-site-icon-placeholder';
                span.setAttribute('data-ap-site-icon-placeholder', '');
                span.setAttribute('aria-hidden', 'true');
                span.textContent = 'No icon';
                preview.insertBefore(span, status || null);
            }
            if (status) {
                status.innerHTML = 'No site icon set. Browsers may use a root <code>favicon.ico</code> if present.';
            }
            if (removeWrap) { removeWrap.hidden = true; }
        }
    }

    if (library) {
        library.addEventListener('change', function () {
            if (remove) { remove.checked = false; }
            if (upload) { upload.value = ''; }
            revoke();
            var opt = library.options[library.selectedIndex];
            var id = parseInt(library.value, 10) || 0;
            if (hidden) { hidden.value = String(id); }
            var url = opt ? (opt.getAttribute('data-url') || '') : '';
            setPreview(id > 0 ? url : '', opt ? opt.textContent : '', id);
        });
    }

    if (upload) {
        upload.addEventListener('change', function () {
            if (remove) { remove.checked = false; }
            revoke();
            var file = upload.files && upload.files[0];
            if (!file) { return; }
            objectUrl = URL.createObjectURL(file);
            setPreview(objectUrl, file.name, 0);
            // Hidden ID stays until save creates the attachment; remove would clear.
            if (library && library.value !== '0') {
                // Keep library selection visual until save; upload wins on server.
            }
        });
    }

    if (remove) {
        remove.addEventListener('change', function () {
            if (!remove.checked) { return; }
            if (upload) { upload.value = ''; }
            revoke();
            if (hidden) { hidden.value = '0'; }
            if (library) { library.value = '0'; }
            setPreview('', '', 0);
        });
    }
})();
JS;
    }
}
