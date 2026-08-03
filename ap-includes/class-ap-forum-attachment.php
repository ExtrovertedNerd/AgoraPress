<?php

/**
 * AgoraPress forum attachments — upload, link to posts, quotas.
 *
 * Files are stored via {@see AP_Media} (media library attachment posts under
 * ap-content/uploads/). This class maintains `{prefix}forum_attachments` rows
 * that associate media with forum posts/topics, enforce per-file / per-post /
 * per-user quotas, and track download counts.
 *
 * Site options (seeded by installer; Settings → Forums later):
 * - forum_attachments_enabled (1/0)
 * - forum_attachment_max_size (bytes per file)
 * - forum_attachment_allowed_types (comma-separated extensions)
 * - forum_attachment_max_per_post
 * - forum_attachment_user_quota (total bytes per user; 0 = unlimited)
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Forum attachment API.
 */
class AP_Forum_Attachment
{
    // -------------------------------------------------------------------------
    // Options
    // -------------------------------------------------------------------------

    public const OPTION_ENABLED = 'forum_attachments_enabled';

    public const OPTION_MAX_SIZE = 'forum_attachment_max_size';

    public const OPTION_ALLOWED_TYPES = 'forum_attachment_allowed_types';

    public const OPTION_MAX_PER_POST = 'forum_attachment_max_per_post';

    public const OPTION_USER_QUOTA = 'forum_attachment_user_quota';

    /** Default max file size: 2 MiB. */
    public const DEFAULT_MAX_SIZE = 2097152;

    /** Default allowed extensions (subset of AP_Media allow-list). */
    public const DEFAULT_ALLOWED_TYPES = 'jpg,jpeg,png,gif,webp,pdf,txt,zip';

    /** Default max attachments per forum post. */
    public const DEFAULT_MAX_PER_POST = 5;

    /** Default per-user total storage quota: 10 MiB (0 = unlimited). */
    public const DEFAULT_USER_QUOTA = 10485760;

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * Whether forum attachments are enabled site-wide.
     */
    public static function isEnabled(?AP_DB $db = null): bool
    {
        $raw = self::optionValue(self::OPTION_ENABLED, '1', $db);
        $raw = strtolower(trim($raw));

        return !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
    }

    /**
     * Max bytes per attachment (also capped by AP_Media / PHP limits when uploading).
     */
    public static function maxSizeBytes(?AP_DB $db = null): int
    {
        $raw = self::optionValue(self::OPTION_MAX_SIZE, (string) self::DEFAULT_MAX_SIZE, $db);
        $n = (int) $raw;
        if ($n < 1) {
            $n = self::DEFAULT_MAX_SIZE;
        }

        return $n;
    }

    /**
     * Allowed file extensions (lowercase, no dots).
     *
     * @return list<string>
     */
    public static function allowedExtensions(?AP_DB $db = null): array
    {
        $raw = self::optionValue(self::OPTION_ALLOWED_TYPES, self::DEFAULT_ALLOWED_TYPES, $db);
        $parts = preg_split('/[\s,;|]+/', strtolower($raw)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = ltrim(trim($part), '.');
            if ($part === '' || !preg_match('/^[a-z0-9]+$/', $part)) {
                continue;
            }
            $out[] = $part;
        }
        if ($out === []) {
            $parts = explode(',', self::DEFAULT_ALLOWED_TYPES);
            foreach ($parts as $part) {
                $out[] = trim($part);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Max number of attachments linked to a single forum post.
     */
    public static function maxPerPost(?AP_DB $db = null): int
    {
        $raw = self::optionValue(self::OPTION_MAX_PER_POST, (string) self::DEFAULT_MAX_PER_POST, $db);
        $n = (int) $raw;
        if ($n < 1) {
            $n = self::DEFAULT_MAX_PER_POST;
        }

        return $n;
    }

    /**
     * Per-user total storage quota in bytes (0 = unlimited).
     */
    public static function userQuotaBytes(?AP_DB $db = null): int
    {
        $raw = self::optionValue(self::OPTION_USER_QUOTA, (string) self::DEFAULT_USER_QUOTA, $db);
        $n = (int) $raw;

        return max(0, $n);
    }

    // -------------------------------------------------------------------------
    // Quota / usage
    // -------------------------------------------------------------------------

    /**
     * Total bytes used by a user's forum attachments (includes orphans).
     */
    public static function userUsageBytes(int $userId, ?AP_DB $db = null): int
    {
        if ($userId < 1) {
            return 0;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forum_attachments'));
        $sql = 'SELECT COALESCE(SUM(' . $db->quoteIdentifier('filesize') . '), 0) FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?';

        return (int) $db->getVar($sql, [$userId]);
    }

    /**
     * Count attachments for a forum post (non-orphan only when post_id > 0).
     */
    public static function countForPost(int $postId, ?AP_DB $db = null): int
    {
        if ($postId < 1) {
            return 0;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forum_attachments'));
        $sql = 'SELECT COUNT(*) FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('post_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('is_orphan') . ' = 0';

        return (int) $db->getVar($sql, [$postId]);
    }

    /**
     * Whether a user may upload a file of the given size (and optional post target).
     *
     * @return array{ok: bool, error: string}
     */
    public static function canUpload(
        int $userId,
        int $fileSize,
        ?int $postId = null,
        ?AP_DB $db = null
    ): array {
        if (!self::isEnabled($db)) {
            return ['ok' => false, 'error' => 'Forum attachments are disabled.'];
        }

        if ($userId < 1) {
            return ['ok' => false, 'error' => 'You must be logged in to attach files.'];
        }

        if ($fileSize < 1) {
            return ['ok' => false, 'error' => 'File is empty.'];
        }

        $max = self::maxSizeBytes($db);
        if ($fileSize > $max) {
            return [
                'ok' => false,
                'error' => 'File exceeds the maximum attachment size of '
                    . self::formatBytes($max) . '.',
            ];
        }

        // Also respect global media upload cap when available.
        if (class_exists('AP_Media', false)) {
            $mediaMax = AP_Media::maxUploadBytes();
            if ($fileSize > $mediaMax) {
                return [
                    'ok' => false,
                    'error' => 'File exceeds the maximum upload size of '
                        . self::formatBytes($mediaMax) . '.',
                ];
            }
        }

        $quota = self::userQuotaBytes($db);
        if ($quota > 0) {
            $used = self::userUsageBytes($userId, $db);
            if ($used + $fileSize > $quota) {
                $remaining = max(0, $quota - $used);

                return [
                    'ok' => false,
                    'error' => 'Attachment would exceed your storage quota ('
                        . self::formatBytes($used) . ' of ' . self::formatBytes($quota)
                        . ' used; ' . self::formatBytes($remaining) . ' remaining).',
                ];
            }
        }

        if ($postId !== null && $postId > 0) {
            $count = self::countForPost($postId, $db);
            $limit = self::maxPerPost($db);
            if ($count >= $limit) {
                return [
                    'ok' => false,
                    'error' => 'This post already has the maximum of ' . $limit . ' attachment(s).',
                ];
            }
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Remaining quota bytes for a user (PHP_INT_MAX when unlimited).
     */
    public static function remainingQuotaBytes(int $userId, ?AP_DB $db = null): int
    {
        $quota = self::userQuotaBytes($db);
        if ($quota < 1) {
            return PHP_INT_MAX;
        }

        return max(0, $quota - self::userUsageBytes($userId, $db));
    }

    // -------------------------------------------------------------------------
    // Upload & attach
    // -------------------------------------------------------------------------

    /**
     * Handle a $_FILES-style upload and create a forum attachment row.
     *
     * When post_id is omitted or 0 the row is marked orphan (attach later via
     * {@see self::assignToPost()}). Files are stored through AP_Media.
     *
     * $file keys: name, type, tmp_name, error, size (PHP upload array shape).
     *
     * @param array<string, mixed> $file
     * @param array<string, mixed> $args user_id (required), post_id, topic_id,
     *                                   forum_id, test_mode, post_title, alt_text
     *
     * @return array{
     *   ok: bool,
     *   id: int,
     *   media_id: int,
     *   file: string,
     *   url: string,
     *   type: string,
     *   error: string,
     *   attachment: ?object
     * }
     */
    public static function handleUpload(array $file, array $args = [], ?AP_DB $db = null): array
    {
        $empty = static fn (string $error): array => [
            'ok' => false,
            'id' => 0,
            'media_id' => 0,
            'file' => '',
            'url' => '',
            'type' => '',
            'error' => $error,
            'attachment' => null,
        ];

        if (!class_exists('AP_Media', false)) {
            return $empty('Media library is not available.');
        }

        $db = self::resolveDb($db);
        $userId = max(0, (int) ($args['user_id'] ?? $args['poster_id'] ?? 0));
        $postId = max(0, (int) ($args['post_id'] ?? 0));
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 && !empty($file['tmp_name']) && is_string($file['tmp_name'])) {
            $detected = @filesize($file['tmp_name']);
            $size = is_int($detected) ? $detected : 0;
        }

        $check = self::canUpload($userId, $size, $postId > 0 ? $postId : null, $db);
        if (!$check['ok']) {
            return $empty($check['error']);
        }

        // Extension must be in forum allow-list (stricter than full media library).
        $origName = (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowed = self::allowedExtensions($db);
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            return $empty(
                'File type is not allowed for forum attachments. Allowed: '
                . implode(', ', $allowed) . '.'
            );
        }

        $mediaResult = AP_Media::handleUpload($file, [
            'post_author' => $userId,
            'post_title' => (string) ($args['post_title'] ?? ''),
            'post_content' => '',
            'post_excerpt' => '',
            'alt_text' => (string) ($args['alt_text'] ?? ''),
            'test_mode' => !empty($args['test_mode']),
            'time' => isset($args['time']) ? (int) $args['time'] : null,
        ], $db);

        if (empty($mediaResult['ok']) || (int) ($mediaResult['id'] ?? 0) < 1) {
            return $empty(
                (string) ($mediaResult['error'] ?? 'Upload failed.')
            );
        }

        $mediaId = (int) $mediaResult['id'];
        $filesize = $size;
        $meta = AP_Media::getMetadata($mediaId, $db);
        if (isset($meta['filesize']) && (int) $meta['filesize'] > 0) {
            $filesize = (int) $meta['filesize'];
        } elseif (!empty($mediaResult['file'])) {
            $abs = AP_Media::getAttachedFile($mediaId, $db);
            if ($abs !== '' && is_file($abs)) {
                $filesize = (int) filesize($abs);
            }
        }

        // Re-check size after media layer (in case PHP size was wrong).
        $recheck = self::canUpload($userId, max(1, $filesize), $postId > 0 ? $postId : null, $db);
        if (!$recheck['ok']) {
            AP_Media::deleteAttachment($mediaId, $db);

            return $empty($recheck['error']);
        }

        $topicId = max(0, (int) ($args['topic_id'] ?? 0));
        $forumId = max(0, (int) ($args['forum_id'] ?? 0));

        if ($postId > 0 && class_exists('AP_Forum', false)) {
            $post = AP_Forum::getPost($postId, $db);
            if ($post === null) {
                AP_Media::deleteAttachment($mediaId, $db);

                return $empty('Forum post not found.');
            }
            $topicId = (int) $post->topic_id;
            $forumId = (int) $post->forum_id;
        }

        $mime = (string) ($mediaResult['type'] ?? '');
        if ($mime === '' && class_exists('AP_Post', false)) {
            $mediaPost = AP_Post::get($mediaId, $db);
            $mime = $mediaPost !== null ? (string) $mediaPost->post_mime_type : '';
        }

        $filename = self::sanitizeFilename($origName);
        if ($filename === '') {
            $filename = basename((string) ($mediaResult['file'] ?? 'attachment'));
        }

        $attachId = self::insertRow([
            'post_id' => $postId,
            'topic_id' => $topicId,
            'forum_id' => $forumId,
            'user_id' => $userId,
            'media_id' => $mediaId,
            'filename' => $filename,
            'mimetype' => $mime,
            'filesize' => $filesize,
            'is_orphan' => $postId < 1 ? 1 : 0,
        ], $db);

        if ($attachId < 1) {
            AP_Media::deleteAttachment($mediaId, $db);

            return $empty('File saved but attachment record could not be created.');
        }

        $row = self::get($attachId, $db);

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_forum_attachment_uploaded', $attachId, $row);
        }

        return [
            'ok' => true,
            'id' => $attachId,
            'media_id' => $mediaId,
            'file' => (string) ($mediaResult['file'] ?? ''),
            'url' => (string) ($mediaResult['url'] ?? ''),
            'type' => $mime,
            'error' => '',
            'attachment' => $row,
        ];
    }

    /**
     * Link an existing media library attachment as a forum attachment on a post.
     *
     * @param array<string, mixed> $args user_id, filename, mimetype, filesize
     *
     * @return int attach_id or 0
     */
    public static function attachMedia(int $mediaId, int $postId, array $args = [], ?AP_DB $db = null): int
    {
        if ($mediaId < 1 || $postId < 1 || !class_exists('AP_Media', false)) {
            return 0;
        }

        $db = self::resolveDb($db);
        if (!self::isEnabled($db)) {
            return 0;
        }

        if (!class_exists('AP_Forum', false)) {
            return 0;
        }

        $post = AP_Forum::getPost($postId, $db);
        if ($post === null) {
            return 0;
        }

        $mediaPost = class_exists('AP_Post', false) ? AP_Post::get($mediaId, $db) : null;
        if ($mediaPost === null || $mediaPost->post_type !== 'attachment') {
            return 0;
        }

        $filesize = (int) ($args['filesize'] ?? 0);
        if ($filesize < 1) {
            $meta = AP_Media::getMetadata($mediaId, $db);
            $filesize = (int) ($meta['filesize'] ?? 0);
            if ($filesize < 1) {
                $abs = AP_Media::getAttachedFile($mediaId, $db);
                if ($abs !== '' && is_file($abs)) {
                    $filesize = (int) filesize($abs);
                }
            }
        }

        $userId = max(0, (int) ($args['user_id'] ?? $mediaPost->post_author ?? 0));
        $check = self::canUpload($userId, max(1, $filesize), $postId, $db);
        if (!$check['ok']) {
            return 0;
        }

        $rel = AP_Media::getAttachedFileRelative($mediaId, $db);
        $ext = strtolower(pathinfo($rel !== '' ? $rel : (string) $mediaPost->post_title, PATHINFO_EXTENSION));
        $allowed = self::allowedExtensions($db);
        if ($ext !== '' && !in_array($ext, $allowed, true)) {
            return 0;
        }

        $filename = trim((string) ($args['filename'] ?? ''));
        if ($filename === '') {
            $filename = $rel !== '' ? basename($rel) : (string) $mediaPost->post_title;
        }
        $filename = self::sanitizeFilename($filename);

        $mime = trim((string) ($args['mimetype'] ?? $mediaPost->post_mime_type ?? ''));

        $attachId = self::insertRow([
            'post_id' => $postId,
            'topic_id' => (int) $post->topic_id,
            'forum_id' => (int) $post->forum_id,
            'user_id' => $userId,
            'media_id' => $mediaId,
            'filename' => $filename,
            'mimetype' => $mime,
            'filesize' => $filesize,
            'is_orphan' => 0,
        ], $db);

        if ($attachId > 0 && function_exists('ap_do_action')) {
            ap_do_action('ap_forum_attachment_attached', $attachId, self::get($attachId, $db));
        }

        return $attachId;
    }

    /**
     * Assign orphan attachment(s) to a forum post (after topic/reply create).
     *
     * @param list<int> $attachIds
     *
     * @return int number successfully assigned
     */
    public static function assignToPost(array $attachIds, int $postId, ?AP_DB $db = null): int
    {
        if ($postId < 1 || $attachIds === []) {
            return 0;
        }

        $db = self::resolveDb($db);
        if (!class_exists('AP_Forum', false)) {
            return 0;
        }

        $post = AP_Forum::getPost($postId, $db);
        if ($post === null) {
            return 0;
        }

        $limit = self::maxPerPost($db);
        $current = self::countForPost($postId, $db);
        $assigned = 0;

        foreach ($attachIds as $rawId) {
            $attachId = (int) $rawId;
            if ($attachId < 1) {
                continue;
            }
            if ($current + $assigned >= $limit) {
                break;
            }

            $row = self::get($attachId, $db);
            if ($row === null) {
                continue;
            }
            // Only orphans (or already on this post) may be reassigned.
            if ((int) $row->is_orphan !== 1 && (int) $row->post_id !== $postId) {
                continue;
            }
            if ((int) $row->post_id === $postId && (int) $row->is_orphan === 0) {
                continue;
            }

            $ok = $db->update('forum_attachments', [
                'post_id' => $postId,
                'topic_id' => (int) $post->topic_id,
                'forum_id' => (int) $post->forum_id,
                'is_orphan' => 0,
            ], ['attach_id' => $attachId]);

            if ($ok !== false) {
                $assigned++;
            }
        }

        return $assigned;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    public static function get(int $attachId, ?AP_DB $db = null): ?object
    {
        if ($attachId < 1) {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forum_attachments'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('attach_id') . ' = ? LIMIT 1',
            [$attachId]
        );

        return $row !== null ? self::normalizeRow($row) : null;
    }

    /**
     * Attachments for a forum post (non-orphans), oldest first.
     *
     * @return list<object>
     */
    public static function getForPost(int $postId, ?AP_DB $db = null): array
    {
        if ($postId < 1) {
            return [];
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forum_attachments'));
        $sql = 'SELECT * FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('post_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('is_orphan') . ' = 0'
            . ' ORDER BY ' . $db->quoteIdentifier('attach_id') . ' ASC';

        $rows = $db->getResults($sql, [$postId]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizeRow($row);
        }

        return $out;
    }

    /**
     * Attachments for all posts in a topic.
     *
     * @return list<object>
     */
    public static function getForTopic(int $topicId, ?AP_DB $db = null): array
    {
        if ($topicId < 1) {
            return [];
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forum_attachments'));
        $sql = 'SELECT * FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('is_orphan') . ' = 0'
            . ' ORDER BY ' . $db->quoteIdentifier('post_id') . ' ASC, '
            . $db->quoteIdentifier('attach_id') . ' ASC';

        $rows = $db->getResults($sql, [$topicId]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizeRow($row);
        }

        return $out;
    }

    /**
     * Orphan attachments for a user (uploaded but not yet linked to a post).
     *
     * @return list<object>
     */
    public static function getOrphansForUser(int $userId, ?AP_DB $db = null): array
    {
        if ($userId < 1) {
            return [];
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forum_attachments'));
        $sql = 'SELECT * FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('is_orphan') . ' = 1'
            . ' ORDER BY ' . $db->quoteIdentifier('attach_id') . ' ASC';

        $rows = $db->getResults($sql, [$userId]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizeRow($row);
        }

        return $out;
    }

    /**
     * Theme-friendly display row.
     *
     * @return array<string, mixed>
     */
    public static function toDisplayRow(object $row, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $mediaId = (int) ($row->media_id ?? 0);
        $url = '';
        if ($mediaId > 0 && class_exists('AP_Media', false)) {
            $url = AP_Media::getAttachmentUrl($mediaId, $db);
        }

        $mime = (string) ($row->mimetype ?? '');
        $isImage = class_exists('AP_Media', false)
            ? AP_Media::isImageMime($mime)
            : str_starts_with(strtolower($mime), 'image/');

        return [
            'id' => (int) ($row->attach_id ?? 0),
            'post_id' => (int) ($row->post_id ?? 0),
            'topic_id' => (int) ($row->topic_id ?? 0),
            'forum_id' => (int) ($row->forum_id ?? 0),
            'user_id' => (int) ($row->user_id ?? 0),
            'media_id' => $mediaId,
            'filename' => (string) ($row->filename ?? ''),
            'mimetype' => $mime,
            'filesize' => (int) ($row->filesize ?? 0),
            'filesize_human' => self::formatBytes((int) ($row->filesize ?? 0)),
            'download_count' => (int) ($row->download_count ?? 0),
            'url' => $url,
            'is_image' => $isImage,
            'is_orphan' => (int) ($row->is_orphan ?? 0) === 1,
            'created_at' => (string) ($row->created_at ?? ''),
        ];
    }

    /**
     * Display rows for a post's attachments.
     *
     * @return list<array<string, mixed>>
     */
    public static function getDisplayForPost(int $postId, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $out = [];
        foreach (self::getForPost($postId, $db) as $row) {
            $out[] = self::toDisplayRow($row, $db);
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Delete / downloads
    // -------------------------------------------------------------------------

    /**
     * Delete a forum attachment row and optionally the media file.
     */
    public static function delete(int $attachId, bool $deleteFile = true, ?AP_DB $db = null): bool
    {
        if ($attachId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $row = self::get($attachId, $db);
        if ($row === null) {
            return false;
        }

        $mediaId = (int) $row->media_id;
        $result = $db->delete('forum_attachments', ['attach_id' => $attachId]);
        if ($result === false) {
            return false;
        }

        if ($deleteFile && $mediaId > 0 && class_exists('AP_Media', false)) {
            // Only delete media if no other forum_attachments rows reference it.
            $table = $db->quoteIdentifier($db->table('forum_attachments'));
            $still = (int) $db->getVar(
                'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('media_id') . ' = ?',
                [$mediaId]
            );
            if ($still < 1) {
                AP_Media::deleteAttachment($mediaId, $db);
            }
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_forum_attachment_deleted', $attachId, $mediaId);
        }

        return true;
    }

    /**
     * Delete all attachments for a forum post.
     *
     * @return int number deleted
     */
    public static function deleteForPost(int $postId, bool $deleteFile = true, ?AP_DB $db = null): int
    {
        if ($postId < 1) {
            return 0;
        }

        $db = self::resolveDb($db);
        $rows = self::getForPost($postId, $db);
        // Also pick up any rows with this post_id that might still be marked orphan.
        $table = $db->quoteIdentifier($db->table('forum_attachments'));
        $extra = $db->getResults(
            'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('post_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('is_orphan') . ' = 1',
            [$postId]
        );
        foreach ($extra as $row) {
            $rows[] = self::normalizeRow($row);
        }

        $n = 0;
        $seen = [];
        foreach ($rows as $row) {
            $id = (int) $row->attach_id;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (self::delete($id, $deleteFile, $db)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Delete all attachments for a topic (force-delete cleanup).
     *
     * @return int number deleted
     */
    public static function deleteForTopic(int $topicId, bool $deleteFile = true, ?AP_DB $db = null): int
    {
        if ($topicId < 1) {
            return 0;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forum_attachments'));
        $rows = $db->getResults(
            'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' = ?',
            [$topicId]
        );

        $n = 0;
        foreach ($rows as $row) {
            $row = self::normalizeRow($row);
            if (self::delete((int) $row->attach_id, $deleteFile, $db)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Increment download counter.
     */
    public static function incrementDownload(int $attachId, ?AP_DB $db = null): bool
    {
        if ($attachId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $row = self::get($attachId, $db);
        if ($row === null) {
            return false;
        }

        $result = $db->update('forum_attachments', [
            'download_count' => (int) $row->download_count + 1,
        ], ['attach_id' => $attachId]);

        return $result !== false;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    private static function insertRow(array $data, AP_DB $db): int
    {
        $now = self::nowLocal();
        $row = [
            'post_id' => max(0, (int) ($data['post_id'] ?? 0)),
            'topic_id' => max(0, (int) ($data['topic_id'] ?? 0)),
            'forum_id' => max(0, (int) ($data['forum_id'] ?? 0)),
            'user_id' => max(0, (int) ($data['user_id'] ?? 0)),
            'media_id' => max(0, (int) ($data['media_id'] ?? 0)),
            'filename' => self::truncate((string) ($data['filename'] ?? ''), 255),
            'mimetype' => self::truncate((string) ($data['mimetype'] ?? ''), 100),
            'filesize' => max(0, (int) ($data['filesize'] ?? 0)),
            'download_count' => 0,
            'is_orphan' => !empty($data['is_orphan']) ? 1 : 0,
            'created_at' => $now,
        ];

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_pre_forum_attachment_insert', $row);
        }

        $result = $db->insert('forum_attachments', $row);
        if ($result === false) {
            return 0;
        }

        return (int) $db->lastInsertId();
    }

    private static function normalizeRow(object $row): object
    {
        $row->attach_id = (int) ($row->attach_id ?? 0);
        $row->post_id = (int) ($row->post_id ?? 0);
        $row->topic_id = (int) ($row->topic_id ?? 0);
        $row->forum_id = (int) ($row->forum_id ?? 0);
        $row->user_id = (int) ($row->user_id ?? 0);
        $row->media_id = (int) ($row->media_id ?? 0);
        $row->filesize = (int) ($row->filesize ?? 0);
        $row->download_count = (int) ($row->download_count ?? 0);
        $row->is_orphan = (int) ($row->is_orphan ?? 0);
        $row->filename = (string) ($row->filename ?? '');
        $row->mimetype = (string) ($row->mimetype ?? '');
        $row->created_at = (string) ($row->created_at ?? '');

        return $row;
    }

    private static function sanitizeFilename(string $name): string
    {
        $name = str_replace(["\0", '/', '\\'], '', $name);
        $name = trim($name);
        // Strip path components just in case.
        $name = basename(str_replace('\\', '/', $name));
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 255);
        } else {
            $name = substr($name, 0, 255);
        }

        return $name;
    }

    private static function truncate(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        return substr($value, 0, $max);
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $n = (float) $bytes;
        foreach ($units as $unit) {
            $n /= 1024;
            if ($n < 1024) {
                return round($n, $n >= 10 ? 0 : 1) . ' ' . $unit;
            }
        }

        return round($n, 1) . ' PB';
    }

    private static function nowLocal(): string
    {
        if (class_exists('AP_Forum', false) && defined('AP_Forum::EMPTY_DATETIME')) {
            // Prefer shared clock when available.
        }
        if (function_exists('ap_current_time')) {
            return (string) ap_current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }

    private static function optionValue(string $name, string $default, ?AP_DB $db): string
    {
        if (class_exists('AP_Options', false)) {
            return (string) AP_Options::get($name, $default, $db);
        }
        if (function_exists('ap_get_option')) {
            return (string) ap_get_option($name, $default, $db);
        }

        return $default;
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('Database connection is not available.');
    }
}
