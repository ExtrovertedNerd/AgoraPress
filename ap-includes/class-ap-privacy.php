<?php

/**
 * AgoraPress privacy tools — GDPR-style personal data export and erase.
 *
 * Collects personal data for a user across core tables (profile, usermeta,
 * posts/pages/attachments, comments) and forum tables when present (topics,
 * forum posts, private messages, group memberships, moderation, unread track).
 * Erase anonymizes content ownership and removes the account.
 *
 * Privacy policy page selector uses option {@see self::OPTION_POLICY_PAGE}
 * (WordPress-compatible name for WXR/import familiarity).
 *
 * Extensibility:
 * - Filter `ap_privacy_export_data` — merge extra groups into the export package.
 * - Filter `ap_privacy_erase_data` — perform plugin cleanup before account delete.
 * - Action `ap_privacy_personal_data_exported` / `ap_privacy_personal_data_erased`.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Personal data export / erase and privacy policy helpers.
 */
class AP_Privacy
{
    /** Option: published page ID used as the privacy policy. */
    public const OPTION_POLICY_PAGE = 'wp_page_for_privacy_policy';

    /** Display name used when anonymizing comments / public attribution. */
    public const ANONYMOUS_DISPLAY_NAME = 'Deleted User';

    /** Usermeta keys never included in personal data exports (secrets / tokens). */
    private const EXPORT_META_BLOCKLIST = [
        'session_tokens',
        'ap_session_tokens',
        'ap_password_reset',
        'ap_activation_key',
    ];

    // -------------------------------------------------------------------------
    // Privacy policy page
    // -------------------------------------------------------------------------

    /**
     * Resolve the configured privacy policy page ID (0 = none).
     */
    public static function getPrivacyPolicyPageId(?AP_DB $db = null): int
    {
        $db = self::resolveDb($db);
        $raw = self::optionGet(self::OPTION_POLICY_PAGE, 0, $db);

        return max(0, (int) $raw);
    }

    /**
     * Persist the privacy policy page ID (0 clears the setting).
     */
    public static function setPrivacyPolicyPageId(int $pageId, ?AP_DB $db = null): bool
    {
        $db = self::resolveDb($db);
        $pageId = max(0, $pageId);

        if ($pageId > 0 && class_exists('AP_Post', false)) {
            $page = AP_Post::get($pageId, $db);
            if ($page === null || $page->post_type !== 'page') {
                return false;
            }
        }

        return self::optionUpdate(self::OPTION_POLICY_PAGE, (string) $pageId, $db);
    }

    /**
     * Public URL of the privacy policy page when configured and published, else ''.
     */
    public static function getPrivacyPolicyUrl(?AP_DB $db = null): string
    {
        $id = self::getPrivacyPolicyPageId($db);
        if ($id < 1) {
            return '';
        }

        if (function_exists('ap_get_permalink')) {
            $url = ap_get_permalink($id, $db);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        if (class_exists('AP_Post', false)) {
            $page = AP_Post::get($id, $db);
            if ($page !== null && $page->post_status === 'publish' && $page->post_name !== '') {
                if (function_exists('ap_home_url')) {
                    return rtrim((string) ap_home_url('/', $db), '/') . '/' . ltrim($page->post_name, '/') . '/';
                }
            }
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Resolve users
    // -------------------------------------------------------------------------

    /**
     * Find a user by numeric ID, login, or email (same rules as auth lookup).
     */
    public static function resolveUser(string|int $identifier, ?AP_DB $db = null): ?AP_User
    {
        $db = self::resolveDb($db);

        if (is_int($identifier) || (is_string($identifier) && ctype_digit(trim($identifier)))) {
            $id = (int) $identifier;
            if ($id > 0) {
                return AP_User::getById($id, $db);
            }
        }

        $raw = trim((string) $identifier);
        if ($raw === '') {
            return null;
        }

        $user = AP_User::getByLogin($raw, $db);
        if ($user !== null) {
            return $user;
        }

        if (str_contains($raw, '@')) {
            return AP_User::getByEmail($raw, $db);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Export
    // -------------------------------------------------------------------------

    /**
     * Build a personal-data export package for a user.
     *
     * @return array{
     *   ok: bool,
     *   errors: list<string>,
     *   user_id: int,
     *   generated_at: string,
     *   site: string,
     *   groups: list<array{group_id: string, group_label: string, item_count: int, data: list<array<string, mixed>>}>
     * }
     */
    public static function exportPersonalData(int $userId, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $empty = [
            'ok' => false,
            'errors' => [],
            'user_id' => max(0, $userId),
            'generated_at' => gmdate('c'),
            'site' => self::siteLabel($db),
            'groups' => [],
        ];

        if ($userId < 1) {
            $empty['errors'][] = 'Invalid user ID.';

            return $empty;
        }

        $user = AP_User::getById($userId, $db);
        if ($user === null) {
            $empty['errors'][] = 'User not found.';

            return $empty;
        }

        $groups = [];
        $groups[] = self::group(
            'user',
            'User profile',
            [self::exportUserProfile($user)]
        );
        $groups[] = self::group(
            'usermeta',
            'User meta',
            self::exportUsermeta($userId, $db)
        );
        $groups[] = self::group(
            'posts',
            'Posts and pages',
            self::exportPosts($userId, $db)
        );
        $groups[] = self::group(
            'comments',
            'Comments',
            self::exportComments($userId, $user->user_email, $db)
        );
        $groups[] = self::group(
            'media',
            'Media attachments',
            self::exportMedia($userId, $db)
        );
        $groups[] = self::group(
            'forum_topics',
            'Forum topics',
            self::exportForumTopics($userId, $db)
        );
        $groups[] = self::group(
            'forum_posts',
            'Forum posts',
            self::exportForumPosts($userId, $db)
        );
        $groups[] = self::group(
            'private_messages',
            'Private messages',
            self::exportPrivateMessages($userId, $db)
        );
        $groups[] = self::group(
            'group_memberships',
            'Forum group memberships',
            self::exportGroupMemberships($userId, $db)
        );
        $groups[] = self::group(
            'moderation',
            'Moderation records',
            self::exportModeration($userId, $db)
        );

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_privacy_export_data', $groups, $userId, $db);
            if (is_array($filtered)) {
                $groups = [];
                foreach ($filtered as $g) {
                    if (!is_array($g)) {
                        continue;
                    }
                    $groups[] = self::normalizeGroup($g);
                }
            }
        }

        $package = [
            'ok' => true,
            'errors' => [],
            'user_id' => $userId,
            'generated_at' => gmdate('c'),
            'site' => self::siteLabel($db),
            'groups' => $groups,
        ];

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_privacy_personal_data_exported', $userId, $package, $db);
        }

        return $package;
    }

    /**
     * JSON-encode a personal data export (pretty-printed, unescaped unicode).
     *
     * @return array{ok: bool, errors: list<string>, json: string, filename: string, user_id: int}
     */
    public static function exportPersonalDataJson(int $userId, ?AP_DB $db = null): array
    {
        $package = self::exportPersonalData($userId, $db);
        if (!$package['ok']) {
            return [
                'ok' => false,
                'errors' => $package['errors'],
                'json' => '',
                'filename' => '',
                'user_id' => $userId,
            ];
        }

        $payload = [
            'format' => 'agorapress-personal-data-export',
            'version' => 1,
            'generated_at' => $package['generated_at'],
            'site' => $package['site'],
            'user_id' => $package['user_id'],
            'groups' => $package['groups'],
        ];

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($json) || $json === '') {
            return [
                'ok' => false,
                'errors' => ['Failed to encode personal data as JSON.'],
                'json' => '',
                'filename' => '',
                'user_id' => $userId,
            ];
        }

        $user = AP_User::getById($userId, $db);
        $slug = $user !== null && $user->user_login !== ''
            ? preg_replace('/[^a-zA-Z0-9_\-]+/', '-', $user->user_login) ?? 'user'
            : 'user';
        $slug = trim((string) $slug, '-') ?: 'user';
        $filename = 'agorapress-personal-data-' . $slug . '-' . gmdate('Ymd-His') . '.json';

        return [
            'ok' => true,
            'errors' => [],
            'json' => $json,
            'filename' => $filename,
            'user_id' => $userId,
        ];
    }

    // -------------------------------------------------------------------------
    // Erase
    // -------------------------------------------------------------------------

    /**
     * Erase personal data for a user (anonymize content, then delete account).
     *
     * Content authored by the user is retained for site integrity:
     * - Posts/pages/attachments reassigned to `$args['reassign']` or author 0.
     * - Comments anonymized (author name “Deleted User”, email/url/IP cleared).
     * - Forum topics/posts detach poster identity and scrub IP.
     * - Private messages hard-deleted for both sides.
     * - Group memberships, unread tracks, online rows, bans/warnings removed.
     * - Account + usermeta deleted via {@see AP_User::delete()}.
     *
     * Refuses to erase the sole remaining administrator.
     *
     * @param array{
     *   reassign?: int,
     *   retain_content?: bool
     * } $args
     *
     * @return array{
     *   ok: bool,
     *   errors: list<string>,
     *   warnings: list<string>,
     *   user_id: int,
     *   counts: array<string, int>
     * }
     */
    public static function erasePersonalData(int $userId, array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $result = [
            'ok' => false,
            'errors' => [],
            'warnings' => [],
            'user_id' => max(0, $userId),
            'counts' => [
                'posts_reassigned' => 0,
                'comments_anonymized' => 0,
                'forum_topics' => 0,
                'forum_posts' => 0,
                'private_messages' => 0,
                'group_memberships' => 0,
                'moderation' => 0,
                'tracks' => 0,
            ],
        ];

        if ($userId < 1) {
            $result['errors'][] = 'Invalid user ID.';

            return $result;
        }

        $user = AP_User::getById($userId, $db);
        if ($user === null) {
            $result['errors'][] = 'User not found.';

            return $result;
        }

        if (self::isSoleAdministrator($userId, $db)) {
            $result['errors'][] = 'Cannot erase the only administrator account.';

            return $result;
        }

        $reassign = isset($args['reassign']) ? max(0, (int) $args['reassign']) : 0;
        if ($reassign === $userId) {
            $reassign = 0;
        }
        if ($reassign > 0 && AP_User::getById($reassign, $db) === null) {
            $result['errors'][] = 'Reassign target user does not exist.';

            return $result;
        }

        // Allow plugins to scrub extra stores first.
        if (function_exists('ap_apply_filters')) {
            $extra = ap_apply_filters('ap_privacy_erase_data', $result['counts'], $userId, $args, $db);
            if (is_array($extra)) {
                foreach ($extra as $k => $v) {
                    if (is_string($k) && is_numeric($v)) {
                        $result['counts'][$k] = (int) $v;
                    }
                }
            }
        }

        $result['counts']['posts_reassigned'] = self::reassignPosts($userId, $reassign, $db);
        $result['counts']['comments_anonymized'] = self::anonymizeComments($userId, $user->user_email, $db);
        $result['counts']['forum_topics'] = self::anonymizeForumTopics($userId, $db);
        $result['counts']['forum_posts'] = self::anonymizeForumPosts($userId, $db);
        $result['counts']['private_messages'] = self::deletePrivateMessages($userId, $db);
        $result['counts']['group_memberships'] = self::removeGroupMemberships($userId, $db);
        $result['counts']['moderation'] = self::deleteModerationRecords($userId, $db);
        $result['counts']['tracks'] = self::deleteReadTracks($userId, $db);

        if (class_exists('AP_Online', false)) {
            try {
                AP_Online::removeUser($userId, $db);
            } catch (Throwable) {
                // Optional presence table.
            }
        }

        $deleted = AP_User::delete($userId, $db);
        if (!$deleted) {
            $result['errors'][] = 'Content was anonymized but the user account could not be deleted.';
            $result['warnings'][] = 'Review the user record manually.';

            return $result;
        }

        $result['ok'] = true;

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_privacy_personal_data_erased', $userId, $result, $db);
        }

        return $result;
    }

    /**
     * Whether erasing this user would leave the site without an administrator.
     */
    public static function isSoleAdministrator(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || !class_exists('AP_Roles', false)) {
            return false;
        }

        $db = self::resolveDb($db);
        $roles = AP_Roles::getUserRoles($userId, $db);
        if (!in_array('administrator', $roles, true)) {
            return false;
        }

        $adminCount = AP_User::count(['role' => 'administrator'], $db);

        return $adminCount <= 1;
    }

    // -------------------------------------------------------------------------
    // Export collectors
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function exportUserProfile(AP_User $user): array
    {
        return [
            'ID' => $user->ID,
            'user_login' => $user->user_login,
            'user_nicename' => $user->user_nicename,
            'user_email' => $user->user_email,
            'user_url' => $user->user_url,
            'user_registered' => $user->user_registered,
            'user_status' => $user->user_status,
            'display_name' => $user->display_name,
            // Password hash intentionally omitted.
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function exportUsermeta(int $userId, AP_DB $db): array
    {
        try {
            $table = $db->quoteIdentifier($db->table('usermeta'));
            $rows = $db->getResults(
                'SELECT meta_key, meta_value FROM ' . $table . ' WHERE user_id = ? ORDER BY meta_key ASC',
                [$userId]
            );
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row->meta_key ?? '');
            if ($key === '' || self::isBlockedMetaKey($key)) {
                continue;
            }
            $out[] = [
                'meta_key' => $key,
                'meta_value' => (string) ($row->meta_value ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function exportPosts(int $userId, AP_DB $db): array
    {
        try {
            $table = $db->quoteIdentifier($db->table('posts'));
            $rows = $db->getResults(
                'SELECT ID, post_title, post_name, post_type, post_status, post_date, post_modified, post_excerpt'
                . ' FROM ' . $table
                . ' WHERE post_author = ? AND post_type IN (?, ?, ?) ORDER BY post_date ASC',
                [$userId, 'post', 'page', 'revision']
            );
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'ID' => (int) ($row->ID ?? $row->id ?? 0),
                'post_title' => (string) ($row->post_title ?? ''),
                'post_name' => (string) ($row->post_name ?? ''),
                'post_type' => (string) ($row->post_type ?? ''),
                'post_status' => (string) ($row->post_status ?? ''),
                'post_date' => (string) ($row->post_date ?? ''),
                'post_modified' => (string) ($row->post_modified ?? ''),
                'post_excerpt' => (string) ($row->post_excerpt ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function exportComments(int $userId, string $email, AP_DB $db): array
    {
        try {
            $table = $db->quoteIdentifier($db->table('comments'));
            $sql = 'SELECT comment_ID, comment_post_ID, comment_author, comment_author_email,'
                . ' comment_author_url, comment_author_IP, comment_date, comment_content,'
                . ' comment_approved, comment_type, user_id'
                . ' FROM ' . $table
                . ' WHERE user_id = ?';
            $params = [$userId];
            if ($email !== '') {
                $sql .= ' OR comment_author_email = ?';
                $params[] = $email;
            }
            $sql .= ' ORDER BY comment_date ASC';
            $rows = $db->getResults($sql, $params);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = (int) ($row->comment_ID ?? $row->comment_id ?? 0);
            if ($id > 0 && isset($seen[$id])) {
                continue;
            }
            if ($id > 0) {
                $seen[$id] = true;
            }
            $out[] = [
                'comment_ID' => $id,
                'comment_post_ID' => (int) ($row->comment_post_ID ?? $row->comment_post_id ?? 0),
                'comment_author' => (string) ($row->comment_author ?? ''),
                'comment_author_email' => (string) ($row->comment_author_email ?? ''),
                'comment_author_url' => (string) ($row->comment_author_url ?? ''),
                'comment_author_IP' => (string) ($row->comment_author_IP ?? $row->comment_author_ip ?? ''),
                'comment_date' => (string) ($row->comment_date ?? ''),
                'comment_content' => (string) ($row->comment_content ?? ''),
                'comment_approved' => (string) ($row->comment_approved ?? ''),
                'comment_type' => (string) ($row->comment_type ?? ''),
                'user_id' => (int) ($row->user_id ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function exportMedia(int $userId, AP_DB $db): array
    {
        try {
            $table = $db->quoteIdentifier($db->table('posts'));
            $rows = $db->getResults(
                'SELECT ID, post_title, post_name, post_status, post_date, post_mime_type, guid'
                . ' FROM ' . $table
                . ' WHERE post_author = ? AND post_type = ? ORDER BY post_date ASC',
                [$userId, 'attachment']
            );
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'ID' => (int) ($row->ID ?? $row->id ?? 0),
                'post_title' => (string) ($row->post_title ?? ''),
                'post_name' => (string) ($row->post_name ?? ''),
                'post_status' => (string) ($row->post_status ?? ''),
                'post_date' => (string) ($row->post_date ?? ''),
                'post_mime_type' => (string) ($row->post_mime_type ?? ''),
                'guid' => (string) ($row->guid ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function exportForumTopics(int $userId, AP_DB $db): array
    {
        if (!self::tableLikelyExists($db, 'topics')) {
            return [];
        }

        try {
            $table = $db->quoteIdentifier($db->table('topics'));
            $rows = $db->getResults(
                'SELECT topic_id, forum_id, topic_title, topic_slug, topic_status, topic_type,'
                . ' topic_approved, topic_views, reply_count, topic_time, topic_last_post_time'
                . ' FROM ' . $table
                . ' WHERE topic_poster = ? ORDER BY topic_time ASC',
                [$userId]
            );
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'topic_id' => (int) ($row->topic_id ?? 0),
                'forum_id' => (int) ($row->forum_id ?? 0),
                'topic_title' => (string) ($row->topic_title ?? ''),
                'topic_slug' => (string) ($row->topic_slug ?? ''),
                'topic_status' => (string) ($row->topic_status ?? ''),
                'topic_type' => (string) ($row->topic_type ?? ''),
                'topic_approved' => (int) ($row->topic_approved ?? 0),
                'topic_views' => (int) ($row->topic_views ?? 0),
                'reply_count' => (int) ($row->reply_count ?? 0),
                'topic_time' => (string) ($row->topic_time ?? ''),
                'topic_last_post_time' => (string) ($row->topic_last_post_time ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function exportForumPosts(int $userId, AP_DB $db): array
    {
        if (!self::tableLikelyExists($db, 'forum_posts')) {
            return [];
        }

        try {
            $table = $db->quoteIdentifier($db->table('forum_posts'));
            $rows = $db->getResults(
                'SELECT post_id, topic_id, forum_id, post_subject, post_content, poster_ip,'
                . ' post_time, post_approved, post_position'
                . ' FROM ' . $table
                . ' WHERE poster_id = ? ORDER BY post_time ASC',
                [$userId]
            );
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'post_id' => (int) ($row->post_id ?? 0),
                'topic_id' => (int) ($row->topic_id ?? 0),
                'forum_id' => (int) ($row->forum_id ?? 0),
                'post_subject' => (string) ($row->post_subject ?? ''),
                'post_content' => (string) ($row->post_content ?? ''),
                'poster_ip' => (string) ($row->poster_ip ?? ''),
                'post_time' => (string) ($row->post_time ?? ''),
                'post_approved' => (int) ($row->post_approved ?? 0),
                'post_position' => (int) ($row->post_position ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function exportPrivateMessages(int $userId, AP_DB $db): array
    {
        if (!self::tableLikelyExists($db, 'messages')) {
            return [];
        }

        try {
            $table = $db->quoteIdentifier($db->table('messages'));
            $rows = $db->getResults(
                'SELECT message_id, sender_id, recipient_id, parent_id, subject, message_content,'
                . ' sent_at, read_at, sender_deleted, recipient_deleted'
                . ' FROM ' . $table
                . ' WHERE sender_id = ? OR recipient_id = ? ORDER BY sent_at ASC',
                [$userId, $userId]
            );
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'message_id' => (int) ($row->message_id ?? 0),
                'sender_id' => (int) ($row->sender_id ?? 0),
                'recipient_id' => (int) ($row->recipient_id ?? 0),
                'parent_id' => (int) ($row->parent_id ?? 0),
                'subject' => (string) ($row->subject ?? ''),
                'message_content' => (string) ($row->message_content ?? ''),
                'sent_at' => (string) ($row->sent_at ?? ''),
                'read_at' => $row->read_at !== null ? (string) $row->read_at : null,
                'role' => ((int) ($row->sender_id ?? 0) === $userId) ? 'sender' : 'recipient',
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function exportGroupMemberships(int $userId, AP_DB $db): array
    {
        if (!self::tableLikelyExists($db, 'group_members')) {
            return [];
        }

        try {
            $members = $db->quoteIdentifier($db->table('group_members'));
            $groups = $db->quoteIdentifier($db->table('groups'));
            $rows = $db->getResults(
                'SELECT m.group_id, m.member_role, m.joined_at, g.group_name, g.group_slug'
                . ' FROM ' . $members . ' m'
                . ' LEFT JOIN ' . $groups . ' g ON g.group_id = m.group_id'
                . ' WHERE m.user_id = ? ORDER BY m.joined_at ASC',
                [$userId]
            );
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'group_id' => (int) ($row->group_id ?? 0),
                'group_name' => (string) ($row->group_name ?? ''),
                'group_slug' => (string) ($row->group_slug ?? ''),
                'member_role' => (string) ($row->member_role ?? ''),
                'joined_at' => (string) ($row->joined_at ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function exportModeration(int $userId, AP_DB $db): array
    {
        $out = [];

        if (self::tableLikelyExists($db, 'warnings')) {
            try {
                $table = $db->quoteIdentifier($db->table('warnings'));
                $rows = $db->getResults(
                    'SELECT warning_id, warning_reason, warning_status, warned_at, expires_at'
                    . ' FROM ' . $table . ' WHERE user_id = ?',
                    [$userId]
                );
                foreach ($rows as $row) {
                    $out[] = [
                        'type' => 'warning',
                        'id' => (int) ($row->warning_id ?? 0),
                        'reason' => (string) ($row->warning_reason ?? ''),
                        'status' => (string) ($row->warning_status ?? ''),
                        'created_at' => (string) ($row->warned_at ?? ''),
                        'expires_at' => isset($row->expires_at) && $row->expires_at !== null
                            ? (string) $row->expires_at
                            : '',
                    ];
                }
            } catch (Throwable) {
                // Optional table shape differences.
            }
        }

        if (self::tableLikelyExists($db, 'bans')) {
            try {
                $table = $db->quoteIdentifier($db->table('bans'));
                $rows = $db->getResults(
                    'SELECT ban_id, ban_reason, ban_status, banned_at, expires_at'
                    . ' FROM ' . $table . ' WHERE user_id = ?',
                    [$userId]
                );
                foreach ($rows as $row) {
                    $out[] = [
                        'type' => 'ban',
                        'id' => (int) ($row->ban_id ?? 0),
                        'reason' => (string) ($row->ban_reason ?? ''),
                        'status' => (string) ($row->ban_status ?? ''),
                        'created_at' => (string) ($row->banned_at ?? ''),
                        'expires_at' => isset($row->expires_at) && $row->expires_at !== null
                            ? (string) $row->expires_at
                            : '',
                    ];
                }
            } catch (Throwable) {
                // Optional.
            }
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Erase helpers
    // -------------------------------------------------------------------------

    private static function reassignPosts(int $userId, int $reassign, AP_DB $db): int
    {
        try {
            $table = $db->quoteIdentifier($db->table('posts'));
            $sql = 'UPDATE ' . $table . ' SET post_author = ? WHERE post_author = ?';
            $stmt = $db->query($sql, [$reassign, $userId]);
            if ($stmt === false) {
                return 0;
            }

            return max(0, $db->rowsAffected());
        } catch (Throwable) {
            return 0;
        }
    }

    private static function anonymizeComments(int $userId, string $email, AP_DB $db): int
    {
        $count = 0;
        try {
            $table = $db->quoteIdentifier($db->table('comments'));
            $sql = 'UPDATE ' . $table
                . ' SET comment_author = ?, comment_author_email = ?, comment_author_url = ?,'
                . ' comment_author_IP = ?, user_id = 0, comment_agent = ?'
                . ' WHERE user_id = ?';
            $params = [
                self::ANONYMOUS_DISPLAY_NAME,
                '',
                '',
                '',
                '',
                $userId,
            ];
            $stmt = $db->query($sql, $params);
            if ($stmt !== false) {
                $count += max(0, $db->rowsAffected());
            }

            if ($email !== '') {
                // Guest comments (or any leftover rows) that still carry the email.
                $sql2 = 'UPDATE ' . $table
                    . ' SET comment_author = ?, comment_author_email = ?, comment_author_url = ?,'
                    . ' comment_author_IP = ?, comment_agent = ?'
                    . ' WHERE comment_author_email = ?';
                $stmt2 = $db->query($sql2, [
                    self::ANONYMOUS_DISPLAY_NAME,
                    '',
                    '',
                    '',
                    '',
                    $email,
                ]);
                if ($stmt2 !== false) {
                    $count += max(0, $db->rowsAffected());
                }
            }
        } catch (Throwable) {
            return $count;
        }

        return $count;
    }

    private static function anonymizeForumTopics(int $userId, AP_DB $db): int
    {
        if (!self::tableLikelyExists($db, 'topics')) {
            return 0;
        }

        try {
            $table = $db->quoteIdentifier($db->table('topics'));
            $n = 0;
            $stmt = $db->query(
                'UPDATE ' . $table . ' SET topic_poster = 0 WHERE topic_poster = ?',
                [$userId]
            );
            if ($stmt !== false) {
                $n += max(0, $db->rowsAffected());
            }
            $stmt2 = $db->query(
                'UPDATE ' . $table . ' SET last_poster_id = 0 WHERE last_poster_id = ?',
                [$userId]
            );
            if ($stmt2 !== false) {
                $n += max(0, $db->rowsAffected());
            }

            return $n;
        } catch (Throwable) {
            return 0;
        }
    }

    private static function anonymizeForumPosts(int $userId, AP_DB $db): int
    {
        if (!self::tableLikelyExists($db, 'forum_posts')) {
            return 0;
        }

        try {
            $table = $db->quoteIdentifier($db->table('forum_posts'));
            $n = 0;
            $stmt = $db->query(
                'UPDATE ' . $table
                . ' SET poster_id = 0, poster_ip = ? WHERE poster_id = ?',
                ['', $userId]
            );
            if ($stmt !== false) {
                $n += max(0, $db->rowsAffected());
            }
            $stmt2 = $db->query(
                'UPDATE ' . $table . ' SET post_edit_user = 0 WHERE post_edit_user = ?',
                [$userId]
            );
            if ($stmt2 !== false) {
                $n += max(0, $db->rowsAffected());
            }

            return $n;
        } catch (Throwable) {
            return 0;
        }
    }

    private static function deletePrivateMessages(int $userId, AP_DB $db): int
    {
        if (!self::tableLikelyExists($db, 'messages')) {
            return 0;
        }

        try {
            $table = $db->quoteIdentifier($db->table('messages'));
            $stmt = $db->query(
                'DELETE FROM ' . $table . ' WHERE sender_id = ? OR recipient_id = ?',
                [$userId, $userId]
            );
            if ($stmt === false) {
                return 0;
            }

            return max(0, $db->rowsAffected());
        } catch (Throwable) {
            return 0;
        }
    }

    private static function removeGroupMemberships(int $userId, AP_DB $db): int
    {
        if (!self::tableLikelyExists($db, 'group_members')) {
            return 0;
        }

        try {
            $table = $db->quoteIdentifier($db->table('group_members'));
            $stmt = $db->query(
                'DELETE FROM ' . $table . ' WHERE user_id = ?',
                [$userId]
            );
            if ($stmt === false) {
                return 0;
            }

            return max(0, $db->rowsAffected());
        } catch (Throwable) {
            return 0;
        }
    }

    private static function deleteModerationRecords(int $userId, AP_DB $db): int
    {
        $n = 0;
        foreach (['warnings', 'bans'] as $tableName) {
            if (!self::tableLikelyExists($db, $tableName)) {
                continue;
            }
            try {
                $table = $db->quoteIdentifier($db->table($tableName));
                $stmt = $db->query(
                    'DELETE FROM ' . $table . ' WHERE user_id = ?',
                    [$userId]
                );
                if ($stmt !== false) {
                    $n += max(0, $db->rowsAffected());
                }
            } catch (Throwable) {
                // continue
            }
        }

        // Reports filed by this user (reporter_id when present).
        if (self::tableLikelyExists($db, 'reports')) {
            try {
                $table = $db->quoteIdentifier($db->table('reports'));
                // Schema uses reporter_id in migration 0005.
                $stmt = $db->query(
                    'DELETE FROM ' . $table . ' WHERE reporter_id = ?',
                    [$userId]
                );
                if ($stmt !== false) {
                    $n += max(0, $db->rowsAffected());
                }
            } catch (Throwable) {
                // Optional column name differences.
            }
        }

        return $n;
    }

    private static function deleteReadTracks(int $userId, AP_DB $db): int
    {
        $n = 0;
        foreach (['topic_track', 'forum_track'] as $tableName) {
            if (!self::tableLikelyExists($db, $tableName)) {
                continue;
            }
            try {
                $table = $db->quoteIdentifier($db->table($tableName));
                $stmt = $db->query(
                    'DELETE FROM ' . $table . ' WHERE user_id = ?',
                    [$userId]
                );
                if ($stmt !== false) {
                    $n += max(0, $db->rowsAffected());
                }
            } catch (Throwable) {
                // continue
            }
        }

        return $n;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('No database connection available for privacy tools.');
    }

    private static function optionGet(string $name, mixed $default, AP_DB $db): mixed
    {
        if (function_exists('ap_get_option')) {
            return ap_get_option($name, $default, $db);
        }
        if (class_exists('AP_Options', false)) {
            return AP_Options::get($name, $default, $db);
        }

        return $default;
    }

    private static function optionUpdate(string $name, mixed $value, AP_DB $db): bool
    {
        if (function_exists('ap_update_option')) {
            return (bool) ap_update_option($name, $value, $db);
        }
        if (class_exists('AP_Options', false)) {
            return (bool) AP_Options::update($name, $value, $db);
        }

        return false;
    }

    private static function siteLabel(AP_DB $db): string
    {
        $name = self::optionGet('blogname', 'AgoraPress', $db);
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') {
            $name = 'AgoraPress';
        }
        $url = '';
        if (defined('AP_SITEURL') && is_string(AP_SITEURL)) {
            $url = (string) AP_SITEURL;
        } else {
            $home = self::optionGet('home', '', $db);
            $url = is_string($home) ? $home : '';
        }

        return $url !== '' ? $name . ' (' . $url . ')' : $name;
    }

    /**
     * Soft table presence probe — failures mean “not available”.
     */
    private static function tableLikelyExists(AP_DB $db, string $unprefixed): bool
    {
        static $cache = [];
        $key = $db->table($unprefixed);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $db->query('SELECT 1 FROM ' . $db->quoteIdentifier($key) . ' LIMIT 1');
            $cache[$key] = $stmt !== false;
        } catch (Throwable) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }

    private static function isBlockedMetaKey(string $key): bool
    {
        $key = strtolower($key);
        if (in_array($key, self::EXPORT_META_BLOCKLIST, true)) {
            return true;
        }
        // Session / token-ish keys.
        if (str_contains($key, 'session_token') || str_contains($key, 'auth_token')) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $data
     *
     * @return array{group_id: string, group_label: string, item_count: int, data: list<array<string, mixed>>}
     */
    private static function group(string $id, string $label, array $data): array
    {
        return [
            'group_id' => $id,
            'group_label' => $label,
            'item_count' => count($data),
            'data' => $data,
        ];
    }

    /**
     * @param array<string, mixed> $g
     *
     * @return array{group_id: string, group_label: string, item_count: int, data: list<array<string, mixed>>}
     */
    private static function normalizeGroup(array $g): array
    {
        $data = [];
        if (isset($g['data']) && is_array($g['data'])) {
            foreach ($g['data'] as $item) {
                if (is_array($item)) {
                    $data[] = $item;
                }
            }
        }

        return [
            'group_id' => (string) ($g['group_id'] ?? 'custom'),
            'group_label' => (string) ($g['group_label'] ?? 'Custom'),
            'item_count' => isset($g['item_count']) ? (int) $g['item_count'] : count($data),
            'data' => $data,
        ];
    }
}
