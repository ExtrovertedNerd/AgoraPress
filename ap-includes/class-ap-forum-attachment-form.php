<?php

/**
 * Front-end glue for forum attachments.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

final class AP_Forum_Attachment_Form
{
    public const FIELD = 'ap_forum_attachments';

    /**
     * @return array<array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    public static function normalizeFiles(array $files): array
    {
        if (empty($files)) {
            return [];
        }

        if (isset($files['name']) && is_array($files['name'])) {
            $count = count($files['name']);
            $result = [];
            for ($i = 0; $i < $count; $i++) {
                $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                $name = $files['name'][$i] ?? '';
                if ($error === UPLOAD_ERR_NO_FILE || $name === '') {
                    continue;
                }
                $result[] = [
                    'name' => $name,
                    'type' => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error' => $error,
                    'size' => $files['size'][$i] ?? 0,
                ];
            }
            return $result;
        }

        $error = $files['error'] ?? UPLOAD_ERR_NO_FILE;
        $name = $files['name'] ?? '';
        if ($error === UPLOAD_ERR_NO_FILE || $name === '') {
            return [];
        }

        return [
            [
                'name' => $name,
                'type' => $files['type'] ?? '',
                'tmp_name' => $files['tmp_name'] ?? '',
                'error' => $error,
                'size' => $files['size'] ?? 0,
            ],
        ];
    }

    public static function userMayAttach(int $userId, int $forumId, ?AP_DB $db = null): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if (!AP_Forum_Attachment::isEnabled($db)) {
            return false;
        }

        if (!class_exists('AP_Forum_Permissions')) {
            return true;
        }

        return AP_Forum_Permissions::userCan($userId, $forumId, AP_Forum_Permissions::PERM_ATTACH, $db);
    }

    public static function inputHtml(int $userId, int $forumId, ?AP_DB $db = null): string
    {
        if (!self::userMayAttach($userId, $forumId, $db)) {
            return '';
        }

        $extensions = AP_Forum_Attachment::allowedExtensions($db);
        $acceptAttr = implode(',', array_map(fn($ext) => '.' . $ext, $extensions));
        $maxPerPost = AP_Forum_Attachment::maxPerPost($db);
        $maxSize = AP_Forum_Attachment::maxSizeBytes($db);
        $formattedSize = AP_Forum_Attachment::formatBytes($maxSize);

        $acceptAttrEsc = htmlspecialchars($acceptAttr, ENT_QUOTES, 'UTF-8');
        $maxPerPostEsc = htmlspecialchars((string) $maxPerPost, ENT_QUOTES, 'UTF-8');
        $formattedSizeEsc = htmlspecialchars($formattedSize, ENT_QUOTES, 'UTF-8');
        $allowedListEsc = htmlspecialchars(implode(', ', $extensions), ENT_QUOTES, 'UTF-8');

        return '<p class="ap-forum-attach"><label for="ap_forum_attachments">Attach files</label>' .
               '<input type="file" name="ap_forum_attachments[]" id="ap_forum_attachments" multiple accept="' . $acceptAttrEsc . '" />' .
               '<small class="ap-forum-attach__help">Up to ' . $maxPerPostEsc . ' files, ' . $formattedSizeEsc . ' each. Allowed: ' . $allowedListEsc . '</small></p>';
    }

    /**
     * @return array{saved: int, errors: list<string>}
     */
    public static function processForPost(
        array $files,
        int $userId,
        int $postId,
        int $forumId,
        ?AP_DB $db = null,
        bool $testMode = false
    ): array {
        if (!self::userMayAttach($userId, $forumId, $db) || $postId < 1) {
            return ['saved' => 0, 'errors' => []];
        }

        $normalized = self::normalizeFiles($files);
        $limit = AP_Forum_Attachment::maxPerPost($db);
        $saved = 0;
        $errors = [];

        $processedCount = 0;
        foreach ($normalized as $file) {
            if ($processedCount >= $limit) {
                break;
            }

            $result = AP_Forum_Attachment::handleUpload(
                $file,
                [
                    'user_id' => $userId,
                    'post_id' => $postId,
                    'forum_id' => $forumId,
                    'test_mode' => $testMode,
                ],
                $db
            );

            if ($result['ok']) {
                $saved++;
            } else {
                $errors[] = $file['name'] . ': ' . $result['error'];
            }
            $processedCount++;
        }

        if (count($normalized) > $limit) {
            $errors[] = 'Only ' . $limit . ' attachments per post; extra files were skipped.';
        }

        return ['saved' => $saved, 'errors' => $errors];
    }
}
