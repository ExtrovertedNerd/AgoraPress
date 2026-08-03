<?php

/**
 * AgoraPress “Who’s online” presence tracking.
 *
 * Uses dedicated `{prefix}online` (migration 0005). Each visitor (member or
 * guest) has one row keyed by session_key; session_time is refreshed on
 * activity. Rows older than the configured window are treated as offline and
 * pruned opportunistically.
 *
 * Options:
 * - forum_online_enabled (default 1)
 * - forum_online_window  (seconds, default 900 = 15 minutes)
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Who’s online API.
 */
class AP_Online
{
    public const OPTION_ENABLED = 'forum_online_enabled';

    public const OPTION_WINDOW = 'forum_online_window';

    /** Default activity window in seconds (15 minutes). */
    public const DEFAULT_WINDOW = 900;

    /** Minimum allowed window (1 minute). */
    public const MIN_WINDOW = 60;

    /** Maximum allowed window (24 hours). */
    public const MAX_WINDOW = 86400;

    /** Cookie name for anonymous guest session keys. */
    public const GUEST_COOKIE = 'ap_forum_session';

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * Whether who’s-online tracking is enabled site-wide.
     */
    public static function isEnabled(?AP_DB $db = null): bool
    {
        $raw = self::optionValue(self::OPTION_ENABLED, '1', $db);
        $raw = strtolower(trim($raw));

        return !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
    }

    /**
     * Whether the forum module is enabled.
     */
    public static function isForumModuleEnabled(?AP_DB $db = null): bool
    {
        if (function_exists('ap_is_module_enabled')) {
            return ap_is_module_enabled('forum', $db);
        }
        if (class_exists('AP_Options', false) && method_exists('AP_Options', 'isModuleEnabled')) {
            return AP_Options::isModuleEnabled('forum', $db);
        }

        return true;
    }

    /**
     * Whether online tracking is available (toggle + forum module).
     */
    public static function isAvailable(?AP_DB $db = null): bool
    {
        return self::isEnabled($db) && self::isForumModuleEnabled($db);
    }

    /**
     * Activity window in seconds (rows older than this are offline).
     */
    public static function windowSeconds(?AP_DB $db = null): int
    {
        $raw = self::optionValue(self::OPTION_WINDOW, (string) self::DEFAULT_WINDOW, $db);
        $seconds = (int) $raw;
        if ($seconds < self::MIN_WINDOW) {
            $seconds = self::DEFAULT_WINDOW;
        }
        if ($seconds > self::MAX_WINDOW) {
            $seconds = self::MAX_WINDOW;
        }

        return $seconds;
    }

    // -------------------------------------------------------------------------
    // Presence tracking
    // -------------------------------------------------------------------------

    /**
     * Record or refresh a presence row.
     *
     * @param array<string, mixed> $data Keys:
     *   session_key (required unless auto-generated for tests),
     *   user_id (0 = guest),
     *   session_ip, session_page, session_forum_id, session_topic_id,
     *   guest_name
     * @param array<string, mixed> $args Options:
     *   check_enabled (bool, default true),
     *   prune (bool, default true) — prune stale rows after write
     *
     * @return int online_id of the row, or 0 on failure / disabled
     */
    public static function track(array $data, ?AP_DB $db = null, array $args = []): int
    {
        $db = self::resolveDb($db);
        $checkEnabled = !array_key_exists('check_enabled', $args) || !empty($args['check_enabled']);
        $prune = !array_key_exists('prune', $args) || !empty($args['prune']);

        if ($checkEnabled && !self::isAvailable($db)) {
            return 0;
        }

        $sessionKey = trim((string) ($data['session_key'] ?? ''));
        if ($sessionKey === '') {
            return 0;
        }
        if (function_exists('mb_substr')) {
            $sessionKey = mb_substr($sessionKey, 0, 64);
        } else {
            $sessionKey = substr($sessionKey, 0, 64);
        }
        $sessionKey = str_replace("\0", '', $sessionKey);
        if ($sessionKey === '') {
            return 0;
        }

        $userId = max(0, (int) ($data['user_id'] ?? 0));
        $ip = self::sanitizeIp((string) ($data['session_ip'] ?? $data['ip'] ?? ''));
        $page = self::sanitizePage((string) ($data['session_page'] ?? $data['page'] ?? ''));
        $forumId = max(0, (int) ($data['session_forum_id'] ?? $data['forum_id'] ?? 0));
        $topicId = max(0, (int) ($data['session_topic_id'] ?? $data['topic_id'] ?? 0));
        $guestName = '';
        if ($userId < 1) {
            $guestName = self::sanitizeGuestName((string) ($data['guest_name'] ?? ''));
        }

        $now = self::nowLocal();
        $existing = self::getBySessionKey($sessionKey, $db);

        if ($existing !== null) {
            $update = [
                'user_id' => $userId,
                'session_ip' => $ip !== '' ? $ip : (string) ($existing->session_ip ?? ''),
                'session_time' => $now,
                'session_page' => $page,
                'session_forum_id' => $forumId,
                'session_topic_id' => $topicId,
                'guest_name' => $userId < 1 ? $guestName : '',
            ];
            $ok = $db->update('online', $update, ['session_key' => $sessionKey]);
            if ($ok === false) {
                return 0;
            }
            if ($prune) {
                self::prune($db, ['check_enabled' => false]);
            }

            return (int) ($existing->online_id ?? 0);
        }

        $row = [
            'user_id' => $userId,
            'session_key' => $sessionKey,
            'session_ip' => $ip,
            'session_time' => $now,
            'session_page' => $page,
            'session_forum_id' => $forumId,
            'session_topic_id' => $topicId,
            'guest_name' => $guestName,
        ];

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_pre_online_track', $row);
        }

        $result = $db->insert('online', $row);
        if ($result === false) {
            // Race: another request inserted the same session_key — update.
            $existing = self::getBySessionKey($sessionKey, $db);
            if ($existing === null) {
                return 0;
            }
            $db->update('online', [
                'user_id' => $userId,
                'session_ip' => $ip !== '' ? $ip : (string) ($existing->session_ip ?? ''),
                'session_time' => $now,
                'session_page' => $page,
                'session_forum_id' => $forumId,
                'session_topic_id' => $topicId,
                'guest_name' => $userId < 1 ? $guestName : '',
            ], ['session_key' => $sessionKey]);

            if ($prune) {
                self::prune($db, ['check_enabled' => false]);
            }

            return (int) ($existing->online_id ?? 0);
        }

        $id = (int) $db->lastInsertId();
        if ($prune) {
            self::prune($db, ['check_enabled' => false]);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_online_tracked', $id, $row);
        }

        return $id > 0 ? $id : 0;
    }

    /**
     * Track the current request (resolves user + session key when possible).
     *
     * @param array<string, mixed> $context Optional page / forum_id / topic_id / guest_name / ip / session_key
     * @param array<string, mixed> $args    Passed to track()
     */
    public static function trackCurrent(array $context = [], ?AP_DB $db = null, array $args = []): int
    {
        $db = self::resolveDb($db);
        $checkEnabled = !array_key_exists('check_enabled', $args) || !empty($args['check_enabled']);
        if ($checkEnabled && !self::isAvailable($db)) {
            return 0;
        }

        $userId = max(0, (int) ($context['user_id'] ?? 0));
        if ($userId < 1 && function_exists('ap_get_current_user_id')) {
            $userId = max(0, (int) ap_get_current_user_id());
        }
        if ($userId < 1 && class_exists('AP_Session', false) && method_exists('AP_Session', 'getCurrentUserId')) {
            $userId = max(0, (int) AP_Session::getCurrentUserId());
        }

        $sessionKey = trim((string) ($context['session_key'] ?? ''));
        if ($sessionKey === '') {
            $sessionKey = self::resolveSessionKey($userId, $db);
        }
        if ($sessionKey === '') {
            return 0;
        }

        $data = [
            'session_key' => $sessionKey,
            'user_id' => $userId,
            'session_ip' => (string) ($context['session_ip'] ?? $context['ip'] ?? self::clientIp()),
            'session_page' => (string) ($context['session_page'] ?? $context['page'] ?? ''),
            'session_forum_id' => (int) ($context['session_forum_id'] ?? $context['forum_id'] ?? 0),
            'session_topic_id' => (int) ($context['session_topic_id'] ?? $context['topic_id'] ?? 0),
            'guest_name' => (string) ($context['guest_name'] ?? ''),
        ];

        return self::track($data, $db, $args);
    }

    /**
     * Remove a presence row by session key (logout / session end).
     */
    public static function remove(string $sessionKey, ?AP_DB $db = null): bool
    {
        $sessionKey = trim($sessionKey);
        if ($sessionKey === '') {
            return false;
        }
        $db = self::resolveDb($db);
        $result = $db->delete('online', ['session_key' => $sessionKey]);

        return $result !== false;
    }

    /**
     * Remove all presence rows for a user (e.g. on full logout).
     */
    public static function removeUser(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }
        $db = self::resolveDb($db);
        $result = $db->delete('online', ['user_id' => $userId]);

        return $result !== false;
    }

    /**
     * Delete rows older than the activity window.
     *
     * @param array<string, mixed> $args check_enabled (bool), window (int seconds override)
     *
     * @return int Number of rows deleted (best-effort; 0 if none / unknown)
     */
    public static function prune(?AP_DB $db = null, array $args = []): int
    {
        $db = self::resolveDb($db);
        $checkEnabled = !array_key_exists('check_enabled', $args) || !empty($args['check_enabled']);
        if ($checkEnabled && !self::isAvailable($db)) {
            return 0;
        }

        $window = isset($args['window']) ? (int) $args['window'] : self::windowSeconds($db);
        if ($window < self::MIN_WINDOW) {
            $window = self::DEFAULT_WINDOW;
        }

        $cutoff = self::cutoffDatetime($window);
        $table = $db->quoteIdentifier($db->table('online'));
        $stmt = $db->query(
            'DELETE FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('session_time') . ' < ?',
            [$cutoff]
        );

        return $stmt === false ? 0 : $db->rowsAffected();
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /**
     * Fetch a presence row by session key (any age).
     */
    public static function getBySessionKey(string $sessionKey, ?AP_DB $db = null): ?object
    {
        $sessionKey = trim($sessionKey);
        if ($sessionKey === '') {
            return null;
        }
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('online'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('session_key') . ' = ? LIMIT 1',
            [$sessionKey]
        );

        return $row !== null ? self::normalizeRow($row) : null;
    }

    /**
     * Whether a user currently has an active (non-stale) presence row.
     */
    public static function isUserOnline(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }
        $db = self::resolveDb($db);
        if (!self::isAvailable($db)) {
            return false;
        }

        $cutoff = self::cutoffDatetime(self::windowSeconds($db));
        $table = $db->quoteIdentifier($db->table('online'));
        $found = $db->getVar(
            'SELECT 1 FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('session_time') . ' >= ?'
            . ' LIMIT 1',
            [$userId, $cutoff]
        );

        return $found !== null && $found !== false && (string) $found !== '';
    }

    /**
     * Distinct logged-in users currently online.
     *
     * @param array<string, mixed> $args limit, forum_id (only users on that forum page)
     *
     * @return list<object> Normalized online rows (one per user_id, latest session_time)
     */
    public static function getOnlineUsers(array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        if (!self::isAvailable($db)) {
            return [];
        }

        $cutoff = self::cutoffDatetime(self::windowSeconds($db));
        $table = $db->quoteIdentifier($db->table('online'));
        $limit = isset($args['limit']) ? max(1, min(500, (int) $args['limit'])) : 200;
        $forumId = isset($args['forum_id']) ? max(0, (int) $args['forum_id']) : 0;

        $sql = 'SELECT * FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' > 0'
            . ' AND ' . $db->quoteIdentifier('session_time') . ' >= ?';
        $params = [$cutoff];

        if ($forumId > 0) {
            $sql .= ' AND ' . $db->quoteIdentifier('session_forum_id') . ' = ?';
            $params[] = $forumId;
        }

        $sql .= ' ORDER BY ' . $db->quoteIdentifier('session_time') . ' DESC';

        $rows = $db->getResults($sql, $params);
        $byUser = [];
        foreach ($rows as $row) {
            $norm = self::normalizeRow($row);
            $uid = (int) $norm->user_id;
            if ($uid < 1 || isset($byUser[$uid])) {
                continue;
            }
            $byUser[$uid] = $norm;
            if (count($byUser) >= $limit) {
                break;
            }
        }

        return array_values($byUser);
    }

    /**
     * Guest presence rows currently online.
     *
     * @param array<string, mixed> $args limit, forum_id
     *
     * @return list<object>
     */
    public static function getOnlineGuests(array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        if (!self::isAvailable($db)) {
            return [];
        }

        $cutoff = self::cutoffDatetime(self::windowSeconds($db));
        $table = $db->quoteIdentifier($db->table('online'));
        $limit = isset($args['limit']) ? max(1, min(500, (int) $args['limit'])) : 200;
        $forumId = isset($args['forum_id']) ? max(0, (int) $args['forum_id']) : 0;

        $sql = 'SELECT * FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = 0'
            . ' AND ' . $db->quoteIdentifier('session_time') . ' >= ?';
        $params = [$cutoff];

        if ($forumId > 0) {
            $sql .= ' AND ' . $db->quoteIdentifier('session_forum_id') . ' = ?';
            $params[] = $forumId;
        }

        $sql .= ' ORDER BY ' . $db->quoteIdentifier('session_time') . ' DESC'
            . ' LIMIT ' . $limit;

        $rows = $db->getResults($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizeRow($row);
        }

        return $out;
    }

    /**
     * Count distinct online members.
     */
    public static function countOnlineUsers(?AP_DB $db = null): int
    {
        return count(self::getOnlineUsers(['limit' => 500], $db));
    }

    /**
     * Count guest sessions currently online.
     */
    public static function countOnlineGuests(?AP_DB $db = null): int
    {
        $db = self::resolveDb($db);
        if (!self::isAvailable($db)) {
            return 0;
        }

        $cutoff = self::cutoffDatetime(self::windowSeconds($db));
        $table = $db->quoteIdentifier($db->table('online'));
        $count = $db->getVar(
            'SELECT COUNT(*) FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = 0'
            . ' AND ' . $db->quoteIdentifier('session_time') . ' >= ?',
            [$cutoff]
        );

        return max(0, (int) $count);
    }

    /**
     * Total online (distinct members + guest sessions).
     */
    public static function countOnline(?AP_DB $db = null): int
    {
        return self::countOnlineUsers($db) + self::countOnlineGuests($db);
    }

    /**
     * Theme-friendly who’s-online snapshot.
     *
     * @param array<string, mixed> $args limit, forum_id, include_guests (bool, default true)
     *
     * @return array{
     *   enabled: bool,
     *   window_seconds: int,
     *   total: int,
     *   member_count: int,
     *   guest_count: int,
     *   members: list<array<string, mixed>>,
     *   guests: list<array<string, mixed>>
     * }
     */
    public static function getDisplay(array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $enabled = self::isAvailable($db);
        $window = self::windowSeconds($db);
        $includeGuests = !array_key_exists('include_guests', $args) || !empty($args['include_guests']);

        if (!$enabled) {
            return [
                'enabled' => false,
                'window_seconds' => $window,
                'total' => 0,
                'member_count' => 0,
                'guest_count' => 0,
                'members' => [],
                'guests' => [],
            ];
        }

        $users = self::getOnlineUsers($args, $db);
        $members = [];
        foreach ($users as $row) {
            $members[] = self::rowToDisplay($row, $db);
        }

        $guests = [];
        $guestCount = 0;
        if ($includeGuests) {
            $guestRows = self::getOnlineGuests($args, $db);
            $guestCount = self::countOnlineGuests($db);
            foreach ($guestRows as $row) {
                $guests[] = self::rowToDisplay($row, $db);
            }
        }

        $memberCount = count($members);

        return [
            'enabled' => true,
            'window_seconds' => $window,
            'total' => $memberCount + $guestCount,
            'member_count' => $memberCount,
            'guest_count' => $guestCount,
            'members' => $members,
            'guests' => $guests,
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Resolve a stable session key for presence tracking.
     */
    public static function resolveSessionKey(int $userId = 0, ?AP_DB $db = null): string
    {
        if ($userId > 0 && class_exists('AP_Session', false)) {
            // Prefer a deterministic key from user id + auth cookie material so
            // multiple tabs share one presence row.
            $material = 'user:' . $userId;
            if (method_exists('AP_Session', 'cookieName')) {
                $material .= '|' . AP_Session::cookieName();
            }

            return substr(hash('sha256', $material), 0, 64);
        }

        // Guest: prefer existing cookie / test bag, else generate.
        $existing = self::readGuestCookie();
        if ($existing !== '') {
            return $existing;
        }

        try {
            $key = bin2hex(random_bytes(16));
        } catch (Throwable) {
            $key = substr(hash('sha256', uniqid('ap_guest_', true)), 0, 32);
        }

        self::writeGuestCookie($key);

        return $key;
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowToDisplay(object $row, ?AP_DB $db): array
    {
        $userId = (int) ($row->user_id ?? 0);
        $displayName = '';
        $userLogin = '';
        if ($userId > 0 && class_exists('AP_User', false)) {
            $user = AP_User::getById($userId, $db);
            if ($user !== null) {
                $userLogin = (string) ($user->user_login ?? '');
                $displayName = (string) ($user->display_name ?? '');
                if ($displayName === '') {
                    $displayName = $userLogin;
                }
            }
        }
        if ($userId < 1) {
            $displayName = (string) ($row->guest_name ?? '');
            if ($displayName === '') {
                $displayName = 'Guest';
            }
        }

        return [
            'online_id' => (int) ($row->online_id ?? 0),
            'user_id' => $userId,
            'user_login' => $userLogin,
            'display_name' => $displayName,
            'is_guest' => $userId < 1,
            'session_time' => (string) ($row->session_time ?? ''),
            'session_page' => (string) ($row->session_page ?? ''),
            'session_forum_id' => (int) ($row->session_forum_id ?? 0),
            'session_topic_id' => (int) ($row->session_topic_id ?? 0),
            'guest_name' => (string) ($row->guest_name ?? ''),
        ];
    }

    private static function normalizeRow(object $row): object
    {
        $o = new stdClass();
        $o->online_id = (int) ($row->online_id ?? 0);
        $o->user_id = (int) ($row->user_id ?? 0);
        $o->session_key = (string) ($row->session_key ?? '');
        $o->session_ip = (string) ($row->session_ip ?? '');
        $o->session_time = (string) ($row->session_time ?? '');
        $o->session_page = (string) ($row->session_page ?? '');
        $o->session_forum_id = (int) ($row->session_forum_id ?? 0);
        $o->session_topic_id = (int) ($row->session_topic_id ?? 0);
        $o->guest_name = (string) ($row->guest_name ?? '');

        return $o;
    }

    private static function cutoffDatetime(int $windowSeconds): string
    {
        return date('Y-m-d H:i:s', time() - max(1, $windowSeconds));
    }

    private static function nowLocal(): string
    {
        if (function_exists('ap_current_time')) {
            return (string) ap_current_time('mysql');
        }

        return date('Y-m-d H:i:s');
    }

    private static function sanitizeIp(string $ip): string
    {
        $ip = trim(str_replace("\0", '', $ip));
        if ($ip === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            $ip = mb_substr($ip, 0, 100);
        } else {
            $ip = substr($ip, 0, 100);
        }

        return $ip;
    }

    private static function sanitizePage(string $page): string
    {
        $page = trim(str_replace("\0", '', $page));
        if (function_exists('mb_substr')) {
            $page = mb_substr($page, 0, 255);
        } else {
            $page = substr($page, 0, 255);
        }

        return $page;
    }

    private static function sanitizeGuestName(string $name): string
    {
        $name = trim(str_replace("\0", '', $name));
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 100);
        } else {
            $name = substr($name, 0, 100);
        }

        return $name;
    }

    private static function clientIp(): string
    {
        if (!empty($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
            return self::sanitizeIp($_SERVER['REMOTE_ADDR']);
        }

        return '';
    }

    private static function readGuestCookie(): string
    {
        if (class_exists('AP_Session', false) && AP_Session::isTestMode()) {
            $cookies = AP_Session::getTestCookies();
            $val = $cookies[self::GUEST_COOKIE] ?? '';

            return is_string($val) ? trim($val) : '';
        }
        if (!empty($_COOKIE[self::GUEST_COOKIE]) && is_string($_COOKIE[self::GUEST_COOKIE])) {
            return trim($_COOKIE[self::GUEST_COOKIE]);
        }

        return '';
    }

    private static function writeGuestCookie(string $key): void
    {
        if (class_exists('AP_Session', false) && AP_Session::isTestMode()) {
            // Test bag is writeable via enableTestMode only; store via setcookie path if available.
            // Use a soft side-channel: put in $_COOKIE for same-request reads.
            $_COOKIE[self::GUEST_COOKIE] = $key;

            return;
        }
        if (headers_sent()) {
            $_COOKIE[self::GUEST_COOKIE] = $key;

            return;
        }
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(self::GUEST_COOKIE, $key, [
            'expires' => time() + 86400 * 30,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::GUEST_COOKIE] = $key;
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

        throw new RuntimeException('Database connection is not available for online tracking.');
    }
}
