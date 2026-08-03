<?php

/**
 * AgoraPress private messaging (forum PM).
 *
 * Uses dedicated `{prefix}messages` (migration 0005). Supports:
 * - Send / reply (threaded via parent_id; replies hang under root message)
 * - Inbox / outbox / unread counts
 * - Mark read / unread
 * - Per-user soft-delete (sender_deleted / recipient_deleted); hard purge when both
 * - Optional site toggle `forum_private_messaging_enabled`
 * - Ban checks via {@see AP_Forum_Moderation::isUserBanned()} when available
 * - Staff access for moderation with `manage_forums`
 *
 * Content is stored raw; use {@see formatContent()} / display helpers for safe HTML.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Private messaging API.
 */
class AP_Private_Message
{
    // -------------------------------------------------------------------------
    // Options
    // -------------------------------------------------------------------------

    public const OPTION_ENABLED = 'forum_private_messaging_enabled';

    /** Folder constants for query helpers. */
    public const FOLDER_INBOX = 'inbox';

    public const FOLDER_OUTBOX = 'outbox';

    public const FOLDER_UNREAD = 'unread';

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * Whether private messaging is enabled site-wide.
     *
     * Defaults to on when the option is missing (installer seeds '1').
     */
    public static function isEnabled(?AP_DB $db = null): bool
    {
        $raw = self::optionValue(self::OPTION_ENABLED, '1', $db);
        $raw = strtolower(trim($raw));

        return !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
    }

    /**
     * Whether the forum module is enabled (PMs are forum-scoped).
     */
    public static function isForumModuleEnabled(?AP_DB $db = null): bool
    {
        if (function_exists('ap_is_module_enabled')) {
            return ap_is_module_enabled('forum', $db);
        }
        if (class_exists('AP_Options', false) && method_exists('AP_Options', 'isModuleEnabled')) {
            return AP_Options::isModuleEnabled('forum', $db);
        }

        // Module helpers unavailable (unit tests) — treat as enabled.
        return true;
    }

    /**
     * Whether private messaging is available right now (toggle + forum module).
     */
    public static function isAvailable(?AP_DB $db = null): bool
    {
        return self::isEnabled($db) && self::isForumModuleEnabled($db);
    }

    // -------------------------------------------------------------------------
    // Capability / access
    // -------------------------------------------------------------------------

    /**
     * Whether a user may send private messages (logged in, not banned, PM on).
     */
    public static function userCanSend(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }
        if (!self::isAvailable($db)) {
            return false;
        }
        if (self::userIsBanned($userId, $db)) {
            return false;
        }

        // Prefer explicit / role caps when the roles API is loaded.
        if (class_exists('AP_Roles', false)) {
            if (AP_Roles::userCan($userId, 'manage_forums', null, $db)) {
                return true;
            }
            // Any user with the core "read" cap (all built-in roles) may PM.
            if (!AP_Roles::userCan($userId, 'read', null, $db)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a user may receive private messages (exists, not banned when ban API available).
     *
     * Guests (id < 1) cannot receive. Banned users still receive so staff can
     * contact them, but cannot send replies.
     */
    public static function userCanReceive(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }
        if (!self::isAvailable($db)) {
            return false;
        }

        return true;
    }

    /**
     * Whether a user is a participant (sender or recipient) of a message.
     */
    public static function userIsParticipant(object $message, int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        return (int) ($message->sender_id ?? 0) === $userId
            || (int) ($message->recipient_id ?? 0) === $userId;
    }

    /**
     * Whether a user may view a message (participant or staff with manage_forums).
     */
    public static function userCanView(int $userId, object|int $message, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }

        $msg = is_object($message) ? $message : self::get((int) $message, $db);
        if ($msg === null) {
            return false;
        }

        if (self::userIsParticipant($msg, $userId)) {
            // Soft-deleted for that side is still "owned" for restore until purged.
            return true;
        }

        return self::userCanModerate($userId, $db);
    }

    /**
     * Staff moderation access (view any PM, hard-delete).
     */
    public static function userCanModerate(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }
        if (class_exists('AP_Roles', false)) {
            return AP_Roles::userCan($userId, 'manage_forums', null, $db);
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // CRUD — send / get
    // -------------------------------------------------------------------------

    /**
     * Send a private message. Returns new message_id or 0 on failure.
     *
     * @param array<string, mixed> $data Keys: sender_id, recipient_id, subject, content
     *                                   (or message_content / body), parent_id (reply)
     * @param array<string, mixed> $args Options:
     *                                   check_enabled (bool, default true),
     *                                   check_bans (bool, default true),
     *                                   allow_self (bool, default false),
     *                                   skip_permission (bool, default false) — tests / CLI
     */
    public static function send(array $data, ?AP_DB $db = null, array $args = []): int
    {
        $db = self::resolveDb($db);
        $checkEnabled = !array_key_exists('check_enabled', $args) || !empty($args['check_enabled']);
        $checkBans = !array_key_exists('check_bans', $args) || !empty($args['check_bans']);
        $allowSelf = !empty($args['allow_self']);
        $skipPermission = !empty($args['skip_permission']);

        if ($checkEnabled && !self::isAvailable($db)) {
            return 0;
        }

        $senderId = max(0, (int) ($data['sender_id'] ?? $data['from'] ?? 0));
        $recipientId = max(0, (int) ($data['recipient_id'] ?? $data['to'] ?? $data['user_id'] ?? 0));
        $parentId = max(0, (int) ($data['parent_id'] ?? $data['reply_to'] ?? 0));
        $recipientExplicit = isset($data['recipient_id'])
            || isset($data['to'])
            || isset($data['user_id']);

        if ($senderId < 1) {
            return 0;
        }
        if (!$skipPermission && !self::userCanSend($senderId, $db)) {
            return 0;
        }
        if ($checkBans && self::userIsBanned($senderId, $db)) {
            return 0;
        }

        // Replies: resolve root, inherit subject / recipient when empty, validate access.
        $subject = '';
        if ($parentId > 0) {
            $parent = self::get($parentId, $db);
            if ($parent === null) {
                return 0;
            }
            $rootId = self::rootId($parent);
            $root = $rootId === (int) $parent->message_id ? $parent : self::get($rootId, $db);
            if ($root === null) {
                return 0;
            }
            // Sender must be a participant of the thread (or staff / skip).
            if (
                !$skipPermission
                && !self::userIsParticipant($root, $senderId)
                && !self::userCanModerate($senderId, $db)
            ) {
                return 0;
            }
            // Default recipient: the other root participant.
            if (!$recipientExplicit) {
                $recipientId = (int) $root->sender_id === $senderId
                    ? (int) $root->recipient_id
                    : (int) $root->sender_id;
            }
            $parentId = $rootId;

            $subject = trim((string) ($data['subject'] ?? ''));
            if ($subject === '') {
                $subject = (string) $root->subject;
                if ($subject !== '' && !preg_match('/^re:\s/i', $subject)) {
                    $subject = 'Re: ' . $subject;
                }
            }
        } else {
            $subject = trim((string) ($data['subject'] ?? $data['title'] ?? ''));
        }

        if ($recipientId < 1) {
            return 0;
        }
        if (!$allowSelf && $senderId === $recipientId) {
            return 0;
        }
        if (!$skipPermission && !self::userCanReceive($recipientId, $db)) {
            return 0;
        }

        $content = trim((string) ($data['message_content'] ?? $data['content'] ?? $data['body'] ?? ''));
        if ($content === '') {
            return 0;
        }
        $content = str_replace("\0", '', $content);
        $subject = str_replace("\0", '', $subject);
        if (function_exists('mb_substr')) {
            $subject = mb_substr($subject, 0, 255);
        } else {
            $subject = substr($subject, 0, 255);
        }

        $now = self::nowLocal();
        $row = [
            'sender_id' => $senderId,
            'recipient_id' => $recipientId,
            'parent_id' => $parentId,
            'subject' => $subject,
            'message_content' => $content,
            'sent_at' => (string) ($data['sent_at'] ?? $now),
            'read_at' => null,
            'sender_deleted' => 0,
            'recipient_deleted' => 0,
        ];

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_pre_pm_send', $row, $data, $args);
        }

        if ($db->insert('messages', $row) === false) {
            return 0;
        }

        $id = (int) $db->lastInsertId();
        if ($id < 1) {
            return 0;
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_pm_sent', $id, self::get($id, $db));
        }

        return $id;
    }

    /**
     * Reply in a thread. Recipient defaults to the other participant of the root.
     *
     * @param array<string, mixed> $data Must include sender_id and content; parent_id or message_id of any message in thread
     * @param array<string, mixed> $args Passed to {@see send()}
     */
    public static function reply(array $data, ?AP_DB $db = null, array $args = []): int
    {
        if (!isset($data['parent_id']) && isset($data['message_id'])) {
            $data['parent_id'] = (int) $data['message_id'];
        }
        if (!isset($data['parent_id']) && isset($data['reply_to'])) {
            $data['parent_id'] = (int) $data['reply_to'];
        }

        return self::send($data, $db, $args);
    }

    /**
     * Fetch a message by ID.
     */
    public static function get(int $id, ?AP_DB $db = null): ?object
    {
        if ($id < 1) {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('messages'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('message_id') . ' = ?',
            [$id]
        );

        return $row !== null ? self::normalizeRow($row) : null;
    }

    /**
     * Fetch a message only if the user may view it.
     */
    public static function getForUser(int $id, int $userId, ?AP_DB $db = null): ?object
    {
        $msg = self::get($id, $db);
        if ($msg === null) {
            return null;
        }
        if (!self::userCanView($userId, $msg, $db)) {
            return null;
        }

        return $msg;
    }

    // -------------------------------------------------------------------------
    // Folders / lists
    // -------------------------------------------------------------------------

    /**
     * Inbox for a user (received, not soft-deleted by recipient).
     *
     * @param array<string, mixed> $args Keys: limit, offset, unread_only, order (ASC|DESC)
     *
     * @return list<object>
     */
    public static function getInbox(int $userId, array $args = [], ?AP_DB $db = null): array
    {
        $args['folder'] = self::FOLDER_INBOX;

        return self::queryForUser($userId, $args, $db);
    }

    /**
     * Outbox for a user (sent, not soft-deleted by sender).
     *
     * @param array<string, mixed> $args Keys: limit, offset, order
     *
     * @return list<object>
     */
    public static function getOutbox(int $userId, array $args = [], ?AP_DB $db = null): array
    {
        $args['folder'] = self::FOLDER_OUTBOX;

        return self::queryForUser($userId, $args, $db);
    }

    /**
     * Unread inbox messages.
     *
     * @param array<string, mixed> $args
     *
     * @return list<object>
     */
    public static function getUnread(int $userId, array $args = [], ?AP_DB $db = null): array
    {
        $args['folder'] = self::FOLDER_INBOX;
        $args['unread_only'] = true;

        return self::queryForUser($userId, $args, $db);
    }

    /**
     * Count messages in a folder.
     *
     * @param array<string, mixed> $args folder (inbox|outbox), unread_only
     */
    public static function countForUser(int $userId, array $args = [], ?AP_DB $db = null): int
    {
        if ($userId < 1) {
            return 0;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('messages'));
        $folder = (string) ($args['folder'] ?? self::FOLDER_INBOX);
        $unreadOnly = !empty($args['unread_only']);

        if ($folder === self::FOLDER_OUTBOX) {
            $sql = 'SELECT COUNT(*) FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('sender_id') . ' = ?'
                . ' AND ' . $db->quoteIdentifier('sender_deleted') . ' = 0';
            $params = [$userId];
        } else {
            $sql = 'SELECT COUNT(*) FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('recipient_id') . ' = ?'
                . ' AND ' . $db->quoteIdentifier('recipient_deleted') . ' = 0';
            $params = [$userId];
            if ($unreadOnly || $folder === self::FOLDER_UNREAD) {
                $sql .= ' AND ' . $db->quoteIdentifier('read_at') . ' IS NULL';
            }
        }

        return (int) $db->getVar($sql, $params);
    }

    /**
     * Count unread inbox messages for a user.
     */
    public static function countUnread(int $userId, ?AP_DB $db = null): int
    {
        return self::countForUser($userId, [
            'folder' => self::FOLDER_INBOX,
            'unread_only' => true,
        ], $db);
    }

    /**
     * Query messages for a user folder.
     *
     * @param array<string, mixed> $args
     *
     * @return list<object>
     */
    public static function queryForUser(int $userId, array $args = [], ?AP_DB $db = null): array
    {
        if ($userId < 1) {
            return [];
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('messages'));
        $folder = (string) ($args['folder'] ?? self::FOLDER_INBOX);
        $unreadOnly = !empty($args['unread_only']);
        $limit = max(0, (int) ($args['limit'] ?? 50));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $order = strtoupper((string) ($args['order'] ?? 'DESC'));
        if ($order !== 'ASC') {
            $order = 'DESC';
        }

        if ($folder === self::FOLDER_OUTBOX) {
            $sql = 'SELECT * FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('sender_id') . ' = ?'
                . ' AND ' . $db->quoteIdentifier('sender_deleted') . ' = 0';
            $params = [$userId];
        } else {
            $sql = 'SELECT * FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('recipient_id') . ' = ?'
                . ' AND ' . $db->quoteIdentifier('recipient_deleted') . ' = 0';
            $params = [$userId];
            if ($unreadOnly || $folder === self::FOLDER_UNREAD) {
                $sql .= ' AND ' . $db->quoteIdentifier('read_at') . ' IS NULL';
            }
        }

        $sql .= ' ORDER BY ' . $db->quoteIdentifier('sent_at') . ' ' . $order
            . ', ' . $db->quoteIdentifier('message_id') . ' ' . $order;

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
            if ($offset > 0) {
                $sql .= ' OFFSET ' . $offset;
            }
        }

        $rows = $db->getResults($sql, $params);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizeRow($row);
        }

        return $out;
    }

    /**
     * Full conversation thread for a message (root + all replies), oldest first.
     *
     * Only returns the thread if $userId may view the root (or is staff).
     * Soft-deleted-for-user messages are still included when the user is a
     * participant of the root (so history stays coherent when one side deleted).
     *
     * @return list<object>
     */
    public static function getThread(int $messageId, int $userId = 0, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $msg = self::get($messageId, $db);
        if ($msg === null) {
            return [];
        }

        $rootId = self::rootId($msg);
        $root = $rootId === (int) $msg->message_id ? $msg : self::get($rootId, $db);
        if ($root === null) {
            return [];
        }

        if ($userId > 0 && !self::userCanView($userId, $root, $db)) {
            return [];
        }

        $table = $db->quoteIdentifier($db->table('messages'));
        $sql = 'SELECT * FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('message_id') . ' = ?'
            . ' OR ' . $db->quoteIdentifier('parent_id') . ' = ?'
            . ' ORDER BY ' . $db->quoteIdentifier('sent_at') . ' ASC'
            . ', ' . $db->quoteIdentifier('message_id') . ' ASC';

        $rows = $db->getResults($sql, [$rootId, $rootId]);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizeRow($row);
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Read state
    // -------------------------------------------------------------------------

    /**
     * Mark a message as read (recipient only, or staff).
     */
    public static function markRead(int $messageId, int $userId = 0, ?AP_DB $db = null): bool
    {
        $db = self::resolveDb($db);
        $msg = self::get($messageId, $db);
        if ($msg === null) {
            return false;
        }

        if ($userId > 0) {
            $isRecipient = (int) $msg->recipient_id === $userId;
            if (!$isRecipient && !self::userCanModerate($userId, $db)) {
                return false;
            }
        }

        if ($msg->read_at !== null && $msg->read_at !== '') {
            return true;
        }

        $ok = $db->update('messages', [
            'read_at' => self::nowLocal(),
        ], ['message_id' => $messageId]);

        if ($ok === false) {
            return false;
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_pm_marked_read', $messageId, $userId);
        }

        return true;
    }

    /**
     * Mark a message as unread (clear read_at). Recipient or staff.
     */
    public static function markUnread(int $messageId, int $userId = 0, ?AP_DB $db = null): bool
    {
        $db = self::resolveDb($db);
        $msg = self::get($messageId, $db);
        if ($msg === null) {
            return false;
        }

        if ($userId > 0) {
            $isRecipient = (int) $msg->recipient_id === $userId;
            if (!$isRecipient && !self::userCanModerate($userId, $db)) {
                return false;
            }
        }

        $ok = $db->update('messages', [
            'read_at' => null,
        ], ['message_id' => $messageId]);

        return $ok !== false;
    }

    /**
     * Mark all messages in a thread as read for the recipient.
     */
    public static function markThreadRead(int $messageId, int $userId, ?AP_DB $db = null): int
    {
        if ($userId < 1) {
            return 0;
        }

        $thread = self::getThread($messageId, $userId, $db);
        $n = 0;
        foreach ($thread as $msg) {
            if ((int) $msg->recipient_id !== $userId) {
                continue;
            }
            if ($msg->read_at !== null && $msg->read_at !== '') {
                continue;
            }
            if (self::markRead((int) $msg->message_id, $userId, $db)) {
                $n++;
            }
        }

        return $n;
    }

    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    /**
     * Soft-delete a message for one user (their side of the conversation).
     *
     * When both sides have deleted, the row is hard-purged.
     * Staff with manage_forums may force-delete any message.
     *
     * @param array<string, mixed> $args force (bool) hard-delete; as_staff (bool)
     */
    public static function deleteForUser(
        int $messageId,
        int $userId,
        ?AP_DB $db = null,
        array $args = []
    ): bool {
        if ($messageId < 1 || $userId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $msg = self::get($messageId, $db);
        if ($msg === null) {
            return false;
        }

        $force = !empty($args['force']);
        $asStaff = !empty($args['as_staff']) || self::userCanModerate($userId, $db);

        if ($force && $asStaff) {
            return self::forceDelete($messageId, $userId, $db);
        }

        $isSender = (int) $msg->sender_id === $userId;
        $isRecipient = (int) $msg->recipient_id === $userId;
        if (!$isSender && !$isRecipient) {
            if ($asStaff) {
                return self::forceDelete($messageId, $userId, $db);
            }

            return false;
        }

        $update = [];
        if ($isSender) {
            $update['sender_deleted'] = 1;
        }
        if ($isRecipient) {
            $update['recipient_deleted'] = 1;
        }
        if ($update === []) {
            return false;
        }

        if ($db->update('messages', $update, ['message_id' => $messageId]) === false) {
            return false;
        }

        // Refresh flags.
        $fresh = self::get($messageId, $db);
        if (
            $fresh !== null
            && (int) $fresh->sender_deleted === 1
            && (int) $fresh->recipient_deleted === 1
        ) {
            self::forceDelete($messageId, $userId, $db);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_pm_deleted_for_user', $messageId, $userId);
        }

        return true;
    }

    /**
     * Permanently remove a message row.
     */
    public static function forceDelete(int $messageId, int $actorId = 0, ?AP_DB $db = null): bool
    {
        if ($messageId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $msg = self::get($messageId, $db);
        if ($msg === null) {
            return true;
        }

        $ok = $db->delete('messages', ['message_id' => $messageId]);
        if ($ok === false) {
            return false;
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_pm_force_deleted', $messageId, $actorId, $msg);
        }

        return true;
    }

    /**
     * Soft-delete every message in a thread for one user.
     */
    public static function deleteThreadForUser(int $messageId, int $userId, ?AP_DB $db = null): int
    {
        $thread = self::getThread($messageId, $userId, $db);
        $n = 0;
        foreach ($thread as $msg) {
            if (self::deleteForUser((int) $msg->message_id, $userId, $db)) {
                $n++;
            }
        }

        return $n;
    }

    // -------------------------------------------------------------------------
    // Admin / moderation queries
    // -------------------------------------------------------------------------

    /**
     * Query messages for staff (optional filters). No participant restriction.
     *
     * @param array<string, mixed> $args Keys: sender_id, recipient_id, parent_id,
     *                                   search (subject/content LIKE), limit, offset, order
     *
     * @return list<object>
     */
    public static function query(array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('messages'));
        $where = ['1=1'];
        $params = [];

        if (isset($args['sender_id']) && (int) $args['sender_id'] > 0) {
            $where[] = $db->quoteIdentifier('sender_id') . ' = ?';
            $params[] = (int) $args['sender_id'];
        }
        if (isset($args['recipient_id']) && (int) $args['recipient_id'] > 0) {
            $where[] = $db->quoteIdentifier('recipient_id') . ' = ?';
            $params[] = (int) $args['recipient_id'];
        }
        if (array_key_exists('parent_id', $args)) {
            $where[] = $db->quoteIdentifier('parent_id') . ' = ?';
            $params[] = max(0, (int) $args['parent_id']);
        }
        if (isset($args['message_id']) && (int) $args['message_id'] > 0) {
            $where[] = $db->quoteIdentifier('message_id') . ' = ?';
            $params[] = (int) $args['message_id'];
        }
        $search = trim((string) ($args['search'] ?? $args['s'] ?? ''));
        if ($search !== '') {
            $like = '%' . self::escapeLike($search) . '%';
            $where[] = '('
                . $db->quoteIdentifier('subject') . ' LIKE ? OR '
                . $db->quoteIdentifier('message_content') . ' LIKE ?'
                . ')';
            $params[] = $like;
            $params[] = $like;
        }

        $order = strtoupper((string) ($args['order'] ?? 'DESC'));
        if ($order !== 'ASC') {
            $order = 'DESC';
        }
        $limit = max(0, (int) ($args['limit'] ?? 50));
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $sql = 'SELECT * FROM ' . $table
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $db->quoteIdentifier('sent_at') . ' ' . $order
            . ', ' . $db->quoteIdentifier('message_id') . ' ' . $order;

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
            if ($offset > 0) {
                $sql .= ' OFFSET ' . $offset;
            }
        }

        $rows = $db->getResults($sql, $params);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizeRow($row);
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Display helpers
    // -------------------------------------------------------------------------

    /**
     * Format message body to safe HTML for display.
     *
     * @param array<string, mixed> $formatArgs Passed to AP_Content_Format::format
     */
    public static function formatContent(string $content, array $formatArgs = []): string
    {
        $formatArgs['context'] = $formatArgs['context'] ?? 'private_message';

        if (class_exists('AP_Content_Format', false)) {
            return AP_Content_Format::format($content, $formatArgs);
        }
        if (function_exists('ap_format_content')) {
            return ap_format_content($content, $formatArgs);
        }

        return htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Theme-friendly row for a message.
     *
     * @return array<string, mixed>
     */
    public static function toDisplayRow(object $message, ?AP_DB $db = null): array
    {
        $content = (string) ($message->message_content ?? '');
        $isUnread = ($message->read_at === null || $message->read_at === '');

        return [
            'id' => (int) ($message->message_id ?? 0),
            'message_id' => (int) ($message->message_id ?? 0),
            'sender_id' => (int) ($message->sender_id ?? 0),
            'recipient_id' => (int) ($message->recipient_id ?? 0),
            'parent_id' => (int) ($message->parent_id ?? 0),
            'root_id' => self::rootId($message),
            'subject' => (string) ($message->subject ?? ''),
            'content' => $content,
            'content_html' => self::formatContent($content),
            'sent_at' => (string) ($message->sent_at ?? ''),
            'read_at' => $message->read_at,
            'is_unread' => $isUnread,
            'sender_deleted' => (int) ($message->sender_deleted ?? 0) === 1,
            'recipient_deleted' => (int) ($message->recipient_deleted ?? 0) === 1,
        ];
    }

    /**
     * Theme-friendly inbox/outbox list.
     *
     * @param array<string, mixed> $args Passed to getInbox/getOutbox
     *
     * @return list<array<string, mixed>>
     */
    public static function getFolderDisplay(
        int $userId,
        string $folder = self::FOLDER_INBOX,
        array $args = [],
        ?AP_DB $db = null
    ): array {
        $folder = $folder === self::FOLDER_OUTBOX ? self::FOLDER_OUTBOX : self::FOLDER_INBOX;
        $rows = $folder === self::FOLDER_OUTBOX
            ? self::getOutbox($userId, $args, $db)
            : self::getInbox($userId, $args, $db);

        return array_map(
            static fn (object $m): array => self::toDisplayRow($m, $db),
            $rows
        );
    }

    /**
     * Theme-friendly thread.
     *
     * @return list<array<string, mixed>>
     */
    public static function getThreadDisplay(int $messageId, int $userId = 0, ?AP_DB $db = null): array
    {
        $rows = self::getThread($messageId, $userId, $db);

        return array_map(
            static fn (object $m): array => self::toDisplayRow($m, $db),
            $rows
        );
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Root message id for a thread (parent_id=0 messages are roots).
     */
    public static function rootId(object $message): int
    {
        $parent = (int) ($message->parent_id ?? 0);
        if ($parent < 1) {
            return (int) ($message->message_id ?? 0);
        }

        return $parent;
    }

    /**
     * Whether a message is unread for its recipient.
     */
    public static function isUnread(object $message): bool
    {
        $readAt = $message->read_at ?? null;

        return $readAt === null || $readAt === '';
    }

    private static function userIsBanned(int $userId, ?AP_DB $db): bool
    {
        if ($userId < 1) {
            return false;
        }
        if (class_exists('AP_Forum_Moderation', false)) {
            return AP_Forum_Moderation::isUserBanned($userId, $db);
        }
        if (function_exists('ap_is_user_banned')) {
            return ap_is_user_banned($userId, $db);
        }

        return false;
    }

    private static function normalizeRow(object $row): object
    {
        $o = new stdClass();
        $o->message_id = (int) ($row->message_id ?? 0);
        $o->sender_id = (int) ($row->sender_id ?? 0);
        $o->recipient_id = (int) ($row->recipient_id ?? 0);
        $o->parent_id = (int) ($row->parent_id ?? 0);
        $o->subject = (string) ($row->subject ?? '');
        $o->message_content = (string) ($row->message_content ?? '');
        $o->sent_at = (string) ($row->sent_at ?? '');
        $o->read_at = isset($row->read_at) && $row->read_at !== null && $row->read_at !== ''
            ? (string) $row->read_at
            : null;
        $o->sender_deleted = (int) ($row->sender_deleted ?? 0);
        $o->recipient_deleted = (int) ($row->recipient_deleted ?? 0);

        return $o;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
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

    private static function nowLocal(): string
    {
        if (function_exists('ap_current_time')) {
            return (string) ap_current_time('mysql');
        }

        return date('Y-m-d H:i:s');
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('Database connection is not available for private messages.');
    }
}
